<?php

namespace App\Services\Portals;

use App\Models\City;
use App\Models\Integration;
use App\Models\MercadoLibreCategoryMapping;
use App\Models\MercadoLibreLocationMapping;
use App\Models\MercadoLibrePropertySetting;
use App\Models\PortalCategory;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class MercadoLibrePropertyMapper
{
    public function __construct(protected MercadoLibreClient $client) {}

    public function operations(string $code): array
    {
        $row = $this->sourceRow($code);
        $slug = $this->normalize($row->tipo_negocio ?? '');

        if (str_contains($slug, 'venta') && (str_contains($slug, 'arriendo') || str_contains($slug, 'alquiler'))) {
            return ['sale', 'rent'];
        }

        return str_contains($slug, 'arriendo') || str_contains($slug, 'alquiler')
            ? ['rent']
            : ['sale'];
    }

    public function map(string $code, string $operation, PortalCredential $credential): array
    {
        $row = $this->sourceRow($code);
        $property = $this->localProperty($row);
        $setting = MercadoLibrePropertySetting::firstOrNew([
            'property_id' => $property->id,
            'operation' => $operation,
        ]);
        $typeSlug = Str::slug((string) $row->tipo_inmueble);
        $category = $this->category($setting, $typeSlug, $operation);

        $errors = [];
        $warnings = [];
        $totalArea = $this->number($row->area_terreno ?? null)
            ?? $this->number($row->area_construida ?? null)
            ?? $this->number($row->area_privada ?? null);
        if ($totalArea === null || $totalArea <= 0) {
            $errors[] = 'El área del inmueble debe ser un número mayor que cero.';
        }

        if (! $category || ! $category->is_leaf) {
            $errors[] = "No existe una categoría hoja MCO homologada para {$typeSlug}/{$operation}. Sincroniza el catálogo.";
        }

        $price = $operation === 'rent'
            ? $this->money($row->precio_arriendo ?? null)
            : $this->money($row->precio_venta ?? null);
        if (! $price || $price <= 0) {
            $errors[] = 'El precio de '.($operation === 'rent' ? 'arriendo' : 'venta').' debe ser mayor que cero.';
        }

        $location = $this->resolveLocation($row, $setting, $credential, $errors, $warnings);
        $pictures = $this->pictures($row);
        $maxPictures = (int) ($category?->settings['max_pictures_per_item'] ?? 30);
        $pictures = array_slice($pictures, 0, max(1, $maxPictures));
        if ($pictures === []) {
            $errors[] = 'Mercado Libre exige al menos una imagen pública.';
        }
        $qualityMinimum = $this->qualityMinimum($typeSlug);
        if (count($pictures) < $qualityMinimum) {
            $warnings[] = "La calidad recomendada requiere {$qualityMinimum} imágenes; el inmueble tiene ".count($pictures).'.';
        }

        $attributes = $this->attributes(
            $row,
            $category?->attributes ?? [],
            $setting->attributes ?? [],
            $errors,
            $warnings
        );
        $contact = $this->sellerContact($row);
        $payload = array_filter([
            'title' => $this->title($row, $operation),
            'category_id' => $category?->category_id,
            'price' => $price,
            'currency_id' => config('portals.mercadolibre.currency_id'),
            'available_quantity' => 1,
            'buying_mode' => 'classified',
            'listing_type_id' => $setting->listing_type_id ?: config('portals.mercadolibre.default_listing_type'),
            'condition' => 'used',
            'channels' => ['marketplace'],
            'seller_custom_field' => (string) $row->codigo,
            'pictures' => $pictures,
            'seller_contact' => $contact,
            'location' => $location,
            'attributes' => $attributes,
            'video_id' => $this->videoId($row->video ?? null),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $updatePayload = collect($payload)
            ->only(['title', 'category_id', 'price', 'pictures', 'location', 'attributes', 'video_id'])
            ->filter(fn ($value) => $value !== null)
            ->all();

        return [
            'payload' => $payload,
            'update_payload' => $updatePayload,
            'description' => $this->description($row),
            'show_exact_address' => $setting->show_exact_address ?? (bool) $property->show_exact_address,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'property' => $property,
            'operation' => $operation,
            'source' => [
                'code' => (string) $row->codigo,
                'property_type' => $row->tipo_inmueble,
                'transaction_type' => $row->tipo_negocio,
                'category_id' => $category?->category_id,
                'image_count' => count($pictures),
            ],
        ];
    }

    protected function sourceRow(string $code): stdClass
    {
        $row = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->where('codigo', $code)
            ->first();
        abort_unless($row, 404, 'Propiedad no encontrada en WordPress.');

        return $row;
    }

    protected function resolveLocation(
        stdClass $row,
        MercadoLibrePropertySetting $setting,
        PortalCredential $credential,
        array &$errors,
        array &$warnings
    ): array {
        if ($setting->location) {
            $latitude = $this->number($setting->location['latitude'] ?? null);
            $longitude = $this->number($setting->location['longitude'] ?? null);
            if (empty($setting->location['city']['id']) || empty($setting->location['state']['id'])) {
                $errors[] = 'La ubicación ajustada requiere IDs de ciudad y departamento de Mercado Libre.';
            }
            if ($latitude === null || $longitude === null) {
                $errors[] = 'La ubicación ajustada requiere latitud y longitud numéricas.';
            }

            return $setting->location;
        }

        $departmentName = $this->department($row);
        $department = $this->normalize($departmentName);
        $city = $this->normalize($row->ciudad ?? '');
        $neighborhood = $this->normalize($row->barrio ?? '');
        if ($city === '') {
            $errors[] = 'El inmueble no tiene ciudad.';

            return [];
        }
        if ($department === '') {
            $errors[] = 'No se pudo inferir el departamento del inmueble desde WordPress.';

            return [];
        }

        $mapping = MercadoLibreLocationMapping::where([
            'source_department' => $department,
            'source_city' => $city,
            'source_neighborhood' => $neighborhood,
        ])->first();

        if (! $mapping) {
            $country = $this->client->country($credential);
            if (! $country['ok']) {
                $errors[] = $this->client->errorMessage($country);

                return [];
            }
            $state = collect($country['data']['states'] ?? [])->first(
                fn (array $item) => $this->normalize($item['name'] ?? '') === $department
            );
            if (! $state) {
                $errors[] = "No se encontró el departamento {$departmentName} en Mercado Libre.";

                return [];
            }

            $stateResult = $this->client->state($state['id'], $credential);
            if (! $stateResult['ok']) {
                $errors[] = $this->client->errorMessage($stateResult);

                return [];
            }
            $cityData = collect($stateResult['data']['cities'] ?? [])->first(
                fn (array $item) => $this->normalize($item['name'] ?? '') === $city
            );
            if (! $cityData) {
                $errors[] = "No se encontró la ciudad {$row->ciudad} en Mercado Libre.";

                return [];
            }

            $cityResult = $this->client->city($cityData['id'], $credential);
            if (! $cityResult['ok']) {
                $errors[] = $this->client->errorMessage($cityResult);

                return [];
            }
            $neighborhoodData = collect($cityResult['data']['neighborhoods'] ?? [])->first(
                fn (array $item) => $this->normalize($item['name'] ?? '') === $neighborhood
            );
            if ($neighborhood !== '' && ! $neighborhoodData) {
                $warnings[] = "Mercado Libre no encontró el barrio {$row->barrio}; se publicará con la ciudad.";
            }

            $mapping = MercadoLibreLocationMapping::create([
                'source_department' => $department,
                'source_city' => $city,
                'source_neighborhood' => $neighborhood,
                'state_id' => $state['id'],
                'state_name' => $state['name'],
                'city_id' => $cityData['id'],
                'city_name' => $cityData['name'],
                'neighborhood_id' => $neighborhoodData['id'] ?? null,
                'neighborhood_name' => $neighborhoodData['name'] ?? null,
            ]);
        }

        $latitude = $this->number($row->latitud ?? null);
        $longitude = $this->number($row->longitud ?? null);
        if ($latitude === null || $longitude === null) {
            $errors[] = 'El inmueble requiere latitud y longitud numéricas.';
        }

        return array_filter([
            'address_line' => $this->text($row->direccion_fisica ?? $row->direccion ?? $row->barrio),
            'zip_code' => $this->postalCode($row),
            'neighborhood' => $mapping->neighborhood_id ? [
                'id' => $mapping->neighborhood_id,
                'name' => $mapping->neighborhood_name,
            ] : null,
            'city' => ['id' => $mapping->city_id, 'name' => $mapping->city_name],
            'state' => ['id' => $mapping->state_id, 'name' => $mapping->state_name],
            'country' => ['id' => config('portals.mercadolibre.country_id'), 'name' => 'Colombia'],
            'latitude' => $latitude,
            'longitude' => $longitude,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function attributes(
        stdClass $row,
        array $catalogAttributes,
        array $overrides,
        array &$errors,
        array &$warnings
    ): array {
        if (array_is_list($overrides)) {
            $overrides = collect($overrides)
                ->filter(fn ($attribute) => is_array($attribute) && ! empty($attribute['id']))
                ->keyBy('id')
                ->all();
        }
        $source = [
            'TOTAL_AREA' => $this->number($row->area_terreno ?? null)
                ?? $this->number($row->area_construida ?? null),
            'COVERED_AREA' => $this->number($row->area_construida ?? null)
                ?? $this->number($row->area_privada ?? null),
            'BEDROOMS' => $this->integer($row->habitaciones ?? null) ?? 0,
            'ROOMS' => $this->integer($row->habitaciones ?? null) ?? 0,
            'FULL_BATHROOMS' => $this->integer($row->banos ?? null) ?? 0,
            'PARKING_LOTS' => $this->integer($row->parqueaderos ?? null) ?? 0,
            'WAREHOUSES' => $this->integer($row->depositos ?? $row->deposito ?? null) ?? 0,
            'PROPERTY_AGE' => $this->integer($row->edad ?? null) ?? 0,
            'MAINTENANCE_FEE' => $this->money($row->precio_admin ?? null) ?? 0,
            'FURNISHED' => $this->yesNo($row->amoblado ?? null),
        ];
        if ($this->hasFeature($row, 'mascota')) {
            $source['IS_SUITABLE_FOR_PETS'] = true;
        }

        $result = [];
        foreach ($catalogAttributes as $attribute) {
            $id = $attribute['id'] ?? null;
            if (! $id) {
                continue;
            }
            $required = (bool) ($attribute['tags']['required'] ?? false);
            $value = $overrides[$id] ?? ($source[$id] ?? null);
            if ($value === null || $value === '') {
                if ($required) {
                    $errors[] = "Falta el atributo obligatorio {$id} ({$attribute['name']}).";
                }

                continue;
            }

            $result[] = $this->formatAttribute($attribute, $value);
        }

        if ($result === [] && $catalogAttributes === []) {
            $warnings[] = 'La categoría no tiene atributos sincronizados; vuelve a sincronizar el catálogo.';
        }

        return $result;
    }

    protected function formatAttribute(array $attribute, mixed $value): array
    {
        $id = $attribute['id'];
        if (is_array($value)) {
            return array_filter([
                'id' => $id,
                'value_id' => $value['value_id'] ?? null,
                'value_name' => $value['value_name'] ?? $value['name'] ?? null,
                'value_struct' => $value['value_struct'] ?? null,
            ], fn ($item) => $item !== null);
        }

        $type = $attribute['value_type'] ?? 'string';
        if ($type === 'number_unit') {
            $unit = match ($id) {
                'MAINTENANCE_FEE' => config('portals.mercadolibre.currency_id'),
                'PROPERTY_AGE' => 'años',
                default => $attribute['default_unit'] ?? 'm²',
            };

            return [
                'id' => $id,
                'value_name' => "{$value} {$unit}",
                'value_struct' => ['number' => (float) $value, 'unit' => $unit],
            ];
        }
        if ($type === 'boolean' || is_bool($value)) {
            $name = $value ? 'Sí' : 'No';
            $allowed = collect($attribute['values'] ?? [])->first(
                fn (array $item) => $this->normalize($item['name'] ?? '') === $this->normalize($name)
            );

            return array_filter([
                'id' => $id,
                'value_id' => $allowed['id'] ?? null,
                'value_name' => $name,
            ], fn ($item) => $item !== null);
        }

        return ['id' => $id, 'value_name' => (string) $value];
    }

    protected function pictures(stdClass $row): array
    {
        $ids = collect([$row->foto_portada, ...explode(',', (string) $row->galeria)])
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $urls = DB::connection('wordpress')->table('wp_posts')
            ->whereIn('ID', $ids)
            ->pluck('guid', 'ID');

        return $ids->map(fn (int $id) => $urls[$id] ?? null)
            ->filter()
            ->map(function (string $url): ?array {
                if (str_starts_with($url, 'http://')) {
                    $url = 'https://'.substr($url, 7);
                }

                return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://')
                    ? ['source' => $url]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function category(
        MercadoLibrePropertySetting $setting,
        string $typeSlug,
        string $operation
    ): MercadoLibreCategoryMapping|stdClass|null {
        if (! $setting->category_id) {
            return MercadoLibreCategoryMapping::where([
                'property_type_slug' => $typeSlug,
                'operation' => $operation,
            ])->first();
        }

        $mapping = MercadoLibreCategoryMapping::where('category_id', $setting->category_id)->first();
        if ($mapping) {
            return $mapping;
        }

        $integrationId = Integration::where('slug', 'mercadolibre')->value('id');
        $category = PortalCategory::where([
            'integration_id' => $integrationId,
            'external_id' => $setting->category_id,
        ])->first();
        if (! $category) {
            return null;
        }

        return (object) [
            'category_id' => $category->external_id,
            'is_leaf' => (bool) ($category->metadata['is_leaf'] ?? false),
            'settings' => $category->metadata['settings'] ?? [],
            'attributes' => $category->metadata['attributes'] ?? [],
        ];
    }

    protected function sellerContact(stdClass $row): array
    {
        $consultant = null;
        if ($row->id_funcionario ?? null) {
            $consultant = DB::connection('wordpress')->table('wp_jet_cct_funcionarios')
                ->where('id_empleado', $row->id_funcionario)
                ->orWhere('_ID', $row->id_funcionario)
                ->first();
        }
        $phone = preg_replace('/\D+/', '', (string) ($consultant->celular ?? ''));
        if (str_starts_with($phone, '57')) {
            $phone = substr($phone, 2);
        }
        $email = filter_var($consultant->correo ?? null, FILTER_VALIDATE_EMAIL)
            ? trim($consultant->correo)
            : null;

        return array_filter([
            'contact' => $this->text($consultant->nombre ?? $row->funcionario ?? 'Sucasa Inmobiliaria'),
            'country_code' => '57',
            'phone' => $phone ?: null,
            'country_code2' => '57',
            'phone2' => $phone ?: null,
            'email' => $email,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function localProperty(stdClass $row): Property
    {
        $existing = Property::where('code', (string) $row->codigo)->first();
        if ($existing) {
            return $existing;
        }

        $cityName = $this->text($row->ciudad ?? 'Ciudad sin homologar');
        $department = $this->department($row) ?: 'Colombia';
        $city = City::firstOrCreate(
            ['name' => $cityName, 'department' => $department],
            [
                'dane_code' => '9'.substr((string) sprintf('%u', crc32($department.'|'.$cityName)), 0, 7),
                'country_code' => 'CO',
                'active' => true,
            ]
        );
        $type = PropertyType::firstOrCreate(
            ['slug' => Str::slug((string) $row->tipo_inmueble)],
            ['name' => $row->tipo_inmueble ?: 'Inmueble', 'active' => true]
        );
        $transactionSlug = count($this->operations((string) $row->codigo)) === 2
            ? 'sale_rent'
            : ($this->operations((string) $row->codigo)[0] === 'rent' ? 'rent' : 'sale');
        $transaction = TransactionType::firstOrCreate(
            ['slug' => $transactionSlug],
            [
                'name' => $row->tipo_negocio ?: $transactionSlug,
                'has_sale_price' => $transactionSlug !== 'rent',
                'has_rent_price' => $transactionSlug !== 'sale',
                'has_admin_price' => true,
                'active' => true,
            ]
        );

        return Property::create([
            'code' => (string) $row->codigo,
            'title' => $this->title($row, $transactionSlug === 'rent' ? 'rent' : 'sale'),
            'description' => $this->description($row),
            'condition' => 'used',
            'city_id' => $city->id,
            'address' => $this->text($row->direccion ?? $row->barrio),
            'lat' => $this->number($row->latitud ?? null),
            'lng' => $this->number($row->longitud ?? null),
            'property_type_id' => $type->id,
            'transaction_type_id' => $transaction->id,
            'sale_price' => $this->money($row->precio_venta ?? null),
            'rent_price' => $this->money($row->precio_arriendo ?? null),
            'admin_price' => $this->money($row->precio_admin ?? null),
            'currency' => 'COP',
            'area_total' => $this->number($row->area_terreno ?? $row->area_construida),
            'area_built' => $this->number($row->area_construida ?? null),
            'area_private' => $this->number($row->area_privada ?? null),
            'bedrooms' => $this->integer($row->habitaciones ?? null) ?? 0,
            'bathrooms' => $this->integer($row->banos ?? null) ?? 0,
            'parking_spaces' => $this->integer($row->parqueaderos ?? null) ?? 0,
            'age_years' => $this->integer($row->edad ?? null) ?? 0,
            'furnished' => $this->yesNo($row->amoblado ?? null),
            'status' => 'active',
            'contact_name' => $row->funcionario ?? null,
        ]);
    }

    protected function title(stdClass $row, string $operation): string
    {
        $parts = [
            $operation === 'rent' ? 'Arriendo' : 'Venta',
            $this->text($row->tipo_inmueble),
        ];
        $bedrooms = $this->integer($row->habitaciones ?? null);
        if ($bedrooms !== null) {
            $parts[] = "{$bedrooms} habitaciones";
        }
        if ($row->barrio ?? null) {
            $parts[] = $this->text($row->barrio);
        }

        return Str::limit(implode(' ', array_filter($parts)), 60, '');
    }

    protected function description(stdClass $row): string
    {
        $text = strip_tags((string) (($row->descripcion ?? null) ?: ($row->datos_adicionales ?? null) ?: ($row->punto_referencia ?? null)));
        $text = preg_replace('/https?:\/\/\S+|www\.\S+/i', '', $text);
        $text = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', '', $text);
        $text = preg_replace('/(?:\+?57\s*)?(?:\d[\s.-]*){10,}/', '', $text);
        $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R{3,}/', "\n\n", $text)));

        return Str::limit($text, 49000, '');
    }

    protected function videoId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/))([A-Za-z0-9_-]{6,})~', $url, $match)) {
            return $match[1].';youtube';
        }
        if (str_contains(strtolower($url), 'matterport') && preg_match('~(?:m=|/show/)([A-Za-z0-9_-]+)~', $url, $match)) {
            return $match[1].';matterport';
        }

        return null;
    }

    protected function postalCode(stdClass $row): ?string
    {
        return DB::connection('wordpress')->table('wp_jet_cct_barrios')
            ->whereRaw('LOWER(TRIM(barrio)) = ?', [strtolower(trim((string) $row->barrio))])
            ->whereRaw('LOWER(TRIM(ciudad)) = ?', [strtolower(trim((string) $row->ciudad))])
            ->value('codigo_postal');
    }

    protected function department(stdClass $row): string
    {
        if (! empty($row->departamento)) {
            return $this->text($row->departamento);
        }

        return $this->text(
            DB::connection('wordpress')->table('wp_jet_cct_barrios')
                ->whereRaw('LOWER(TRIM(barrio)) = ?', [strtolower(trim((string) $row->barrio))])
                ->whereRaw('LOWER(TRIM(ciudad)) = ?', [strtolower(trim((string) $row->ciudad))])
                ->value('departamento')
        );
    }

    protected function hasFeature(stdClass $row, string $needle): bool
    {
        $text = implode(' ', [
            $row->interiores ?? '',
            $row->exteriores ?? '',
            $row->alrededores ?? '',
            $row->zonas_sociales ?? '',
        ]);

        return str_contains($this->normalize($text), $this->normalize($needle));
    }

    protected function qualityMinimum(string $type): int
    {
        if (in_array($type, ['casa', 'casa-lote', 'apartamento', 'apartaestudio', 'oficina', 'consultorio'], true)) {
            return 12;
        }
        if ($type === 'parqueadero') {
            return 4;
        }

        return 6;
    }

    protected function normalize(?string $value): string
    {
        return strtolower(Str::ascii(trim((string) $value)));
    }

    protected function text($value): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));
    }

    protected function number($value): ?float
    {
        $clean = str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', (string) $value));

        return is_numeric($clean) ? (float) $clean : null;
    }

    protected function integer($value): ?int
    {
        $clean = preg_replace('/[^0-9\-]/', '', (string) $value);

        return is_numeric($clean) ? (int) $clean : null;
    }

    protected function money($value): ?float
    {
        $clean = preg_replace('/[^0-9]/', '', (string) $value);

        return $clean !== '' ? (float) $clean : null;
    }

    protected function yesNo($value): bool
    {
        return in_array($this->normalize((string) $value), ['si', '1', 'true', 'yes'], true);
    }
}
