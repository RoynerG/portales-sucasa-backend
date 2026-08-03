<?php

namespace App\Services\Portals;

use App\Models\City;
use App\Models\Integration;
use App\Models\Neighborhood;
use App\Models\PortalMapping;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class FincaraizPropertyMapper
{
    private const FEATURE_IDS = [
        'aire acondicionado' => 1,
        'piso en baldosa marmol' => 4,
        'parqueadero visitantes' => 5,
        'jardin' => 7,
        'terraza' => 10,
        'deposito bodega' => 11,
        'conjunto cerrado' => 12,
        'ascensor' => 13,
        'patio' => 16,
        'piscina' => 17,
        'amoblado' => 19,
        'cocina integral' => 20,
        'balcon' => 32,
        'gimnasio' => 103,
        'zona infantil' => 106,
        'zonas verdes' => 107,
        'salon comunal' => 112,
        'vigilancia' => 119,
        'chimenea' => 129,
        'zona de lavanderia' => 134,
        'transporte publico cercano' => 141,
        'seguridad 24 horas' => 147,
        'zona de bbq' => 177,
        'servicio de internet' => 190,
    ];

    public function map(string $code, array $settings = []): array
    {
        if (config('sources.properties') === 'wordpress') {
            $row = $this->sourceRow($code);
            abort_unless($row, 404, 'Propiedad no encontrada en WordPress.');

            return $this->mapProperty($this->localProperty($row), $settings, $row);
        }

        return $this->mapProperty($this->ensureLocalProperty($code), $settings);
    }

    public function ensureLocalProperty(string $code): Property
    {
        if (config('sources.properties') === 'wordpress') {
            $row = $this->sourceRow($code);

            abort_unless($row, 404, 'Propiedad no encontrada en WordPress.');

            return $this->localProperty($row);
        }

        return Property::where('code', $code)->firstOrFail();
    }

    protected function sourceRow(string $code): ?stdClass
    {
        if (config('sources.properties') !== 'wordpress') {
            return null;
        }

        return DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->where('estado', 'Publico')
            ->whereRaw('TRIM(codigo) = ?', [trim($code)])
            ->first();
    }

    public function mapProperty(Property $property, array $settings = [], ?stdClass $source = null): array
    {
        $property->loadMissing(['city', 'propertyType', 'transactionType', 'neighborhood', 'images', 'videos', 'features']);

        $settings = array_merge([
            'client_id' => config('portals.fincaraiz.client_id'),
            'client_agent' => config('portals.fincaraiz.client_agent'),
            'contact_email' => config('portals.fincaraiz.contact_email'),
            'contact_phone' => config('portals.fincaraiz.contact_phone'),
            'contact_whatsapp' => config('portals.fincaraiz.contact_whatsapp'),
            'show_exact_address' => config('portals.fincaraiz.show_exact_address', false),
            'dual_offer' => config('portals.fincaraiz.dual_offer', 'sale'),
        ], array_filter($settings, fn ($value) => $value !== null && $value !== ''));

        $offer = $this->offer($property, $settings);
        $price = $offer === 'rent' || $offer === 'lease'
            ? (float) ($property->rent_price ?? 0)
            : (float) ($property->sale_price ?? 0);
        $propertyType = $this->propertyType($property->propertyType?->slug ?? $property->propertyType?->name);
        $area = (float) ($property->area_built ?? $property->area_total ?? $property->area_land ?? 0);
        $contact = $this->contact($property, $source, $settings);
        $photos = $source ? $this->sourcePhotos($source) : $this->modelPhotos($property);
        $locationId = $this->locationId($property, $settings);
        $categories = $this->categories($property, $source);
        $description = $this->description($property);
        $address = $this->text($property->address ?: $property->address_extra);
        $latitude = $this->number($property->lat);
        $longitude = $this->number($property->lng);

        $payload = [
            'external_code' => trim((string) $property->code),
            'client_id' => (string) ($settings['client_id'] ?? ''),
            'offer' => $offer,
            'property_type' => $propertyType,
            'description' => $description,
            'price' => $price,
            'negotiable' => (bool) $property->price_negotiable,
            'condition' => $this->condition($property->condition),
            'stratum' => $this->stratum($property->stratum),
            'area' => $area,
            'age' => $this->age($property->age_years),
            'address' => ['address' => $address],
            'locations' => array_filter([
                'location_point' => [
                    'longitude' => $longitude,
                    'latitude' => $latitude,
                ],
                'view_map' => ($settings['show_exact_address'] ?? false) ? 0 : 2,
                'location_main_id' => $locationId,
            ], fn ($value) => $value !== null && $value !== ''),
            'capacity' => 0,
            'rooms' => $this->bucket((int) ($property->bedrooms ?? 0), 19, 20),
            'baths' => $this->bucket((int) ($property->bathrooms ?? 0), 9, 10),
            'floor' => $this->bucket((int) ($property->floor_number ?? 0), 16, 18),
            'garages' => $this->bucket((int) ($property->parking_spaces ?? 0), 10, 11),
            'listing_contact' => $contact,
        ];

        if ($agent = $this->positiveInteger($settings['client_agent'] ?? null)) {
            $payload['client_agent'] = $agent;
        }
        if ($property->admin_price !== null) {
            $adminPrice = (float) $property->admin_price;
            $payload['administration'] = array_filter([
                'is_included' => $adminPrice <= 0,
                'price' => $adminPrice > 0 ? $adminPrice : null,
            ], fn ($value) => $value !== null);
        }
        if ((float) ($property->area_private ?? $property->area_land ?? 0) > 0) {
            $payload['living_area'] = (float) ($property->area_private ?? $property->area_land);
        }
        if ($postalCode = $this->text($property->neighborhood?->postal_code)) {
            $payload['postal_code'] = $postalCode;
        }
        if ($categories !== []) {
            $payload['categories'] = $categories;
        }
        if ($photos !== []) {
            $payload['photos'] = $photos;
        }

        $errors = $this->validate($payload, $latitude, $longitude);
        $warnings = [];
        if (! $locationId) {
            $warnings[] = 'Falta homologar el barrio con el UUID de GET /location/{name}; se enviarán solo las coordenadas.';
        }
        if ($photos === []) {
            $warnings[] = 'El inmueble no tiene imágenes públicas para Fincaraíz.';
        } elseif (count($photos) < 6) {
            $warnings[] = 'Se recomiendan al menos 6 imágenes; el inmueble tiene '.count($photos).'.';
        }
        if ($property->transactionType?->slug === 'sale_rent') {
            $warnings[] = 'Fincaraíz admite una oferta por aviso; se usará '.($offer === 'rent' ? 'arriendo' : 'venta').'.';
        }

        return [
            'payload' => $payload,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'property' => $property,
            'source' => [
                'code' => (string) $property->code,
                'offer' => $offer,
                'property_type' => $propertyType,
                'image_count' => count($photos),
                'city' => $property->city?->name,
                'department' => $property->city?->department,
                'neighborhood' => $property->neighborhood?->name,
                'neighborhood_id' => $property->neighborhood_id,
                'location_id' => $locationId,
                'environment' => config('portals.fincaraiz.environment'),
            ],
        ];
    }

    public function saveLocationMapping(string $code, array $location, array $settings = []): array
    {
        $mapped = $this->map($code, $settings);
        /** @var Property $property */
        $property = $mapped['property'];
        if (! $property->neighborhood_id) {
            throw ValidationException::withMessages([
                'location_id' => 'El inmueble no tiene un barrio local al cual asociar la ubicación de Fincaraíz.',
            ]);
        }

        PortalMapping::updateOrCreate(
            [
                'integration_id' => Integration::where('slug', 'fincaraiz')->firstOrFail()->id,
                'mappable_type' => Neighborhood::class,
                'mappable_id' => $property->neighborhood_id,
            ],
            [
                'external_id' => (string) $location['location_id'],
                'external_name' => $this->text($location['name'] ?? ''),
                'extra' => [
                    'location_type' => $this->text($location['location_type'] ?? ''),
                    'country' => $this->text($location['country'] ?? ''),
                    'state' => $this->text($location['state'] ?? ''),
                    'city' => $this->text($location['city'] ?? ''),
                    'environment' => config('portals.fincaraiz.environment'),
                ],
            ]
        );

        return $this->map($code, $settings);
    }

    protected function validate(array $payload, ?float $latitude, ?float $longitude): array
    {
        $errors = [];
        if (! Str::isUuid((string) ($payload['client_id'] ?? ''))) {
            $errors[] = 'FINCARAIZ_CLIENT_ID debe ser el UUID entregado por Fincaraíz.';
        }
        foreach (['external_code', 'description', 'offer', 'property_type'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                $errors[] = "Falta {$field}.";
            }
        }
        if (($payload['price'] ?? 0) <= 0) {
            $errors[] = 'El precio debe ser mayor que cero.';
        }
        if (($payload['area'] ?? 0) <= 0) {
            $errors[] = 'El área construida o total debe ser mayor que cero.';
        }
        if (trim((string) ($payload['address']['address'] ?? '')) === '') {
            $errors[] = 'Falta la dirección del inmueble.';
        }
        if ($latitude === null || $latitude < -90 || $latitude > 90) {
            $errors[] = 'La latitud debe ser numérica y estar entre -90 y 90.';
        }
        if ($longitude === null || $longitude < -180 || $longitude > 180) {
            $errors[] = 'La longitud debe ser numérica y estar entre -180 y 180.';
        }
        if (($payload['listing_contact']['emails'] ?? []) === []) {
            $errors[] = 'Falta un correo válido para FINCARAIZ_CONTACT_EMAIL o para el asesor.';
        }
        if (($payload['listing_contact']['phones'] ?? []) === []) {
            $errors[] = 'Falta un teléfono para FINCARAIZ_CONTACT_PHONE o para el asesor.';
        }

        return $errors;
    }

    protected function contact(Property $property, ?stdClass $source, array $settings): array
    {
        $advisor = null;
        if ($source && ($source->id_funcionario ?? null)) {
            $advisor = DB::connection('wordpress')
                ->table('wp_jet_cct_funcionarios')
                ->where('id_empleado', $source->id_funcionario)
                ->orWhere('_ID', $source->id_funcionario)
                ->first();
        }

        $email = trim((string) ($settings['contact_email']
            ?? $property->contact_email
            ?? $advisor?->correo
            ?? ''));
        $phone = $this->phone($settings['contact_phone']
            ?? $property->contact_phone
            ?? $advisor?->celular
            ?? null);
        $whatsapp = $this->phone($settings['contact_whatsapp']
            ?? $property->contact_whatsapp
            ?? $advisor?->celular
            ?? null);

        return [
            'emails' => filter_var($email, FILTER_VALIDATE_EMAIL) ? [[
                'is_main' => true,
                'email' => $email,
                'sort_order' => 0,
            ]] : [],
            'phones' => $phone ? [[
                'phone' => $phone,
                'is_whatsapp_number' => $whatsapp !== '' && $whatsapp === $phone,
                'is_click_to_call' => true,
                'sort_order' => 0,
            ]] : [],
        ];
    }

    protected function modelPhotos(Property $property): array
    {
        return $property->images
            ->take(30)
            ->values()
            ->map(fn ($image, int $index) => $this->photo((string) $image->url, $index))
            ->filter()
            ->values()
            ->all();
    }

    protected function sourcePhotos(stdClass $row): array
    {
        $ids = collect([$row->foto_portada ?? null, ...explode(',', (string) ($row->galeria ?? ''))])
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->take(30)
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $urls = DB::connection('wordpress')->table('wp_posts')
            ->whereIn('ID', $ids)
            ->pluck('guid', 'ID');

        return $ids
            ->map(fn (int $id, int $index) => isset($urls[$id]) ? $this->photo((string) $urls[$id], $index) : null)
            ->filter()
            ->values()
            ->all();
    }

    protected function photo(string $url, int $index): ?array
    {
        $url = trim($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('~^https?://~i', $url)) {
            return null;
        }

        return [
            'sort_order' => $index + 1,
            'is_main' => $index === 0,
            'image' => $url,
        ];
    }

    protected function categories(Property $property, ?stdClass $source): array
    {
        $text = $source
            ? implode(' ', [
                $source->interiores ?? '',
                $source->exteriores ?? '',
                $source->alrededores ?? '',
                $source->zonas_sociales ?? '',
                $source->amoblado ?? '',
            ])
            : $property->features->pluck('name')->implode(' ');
        $normalized = $this->normalize($text);

        return collect(self::FEATURE_IDS)
            ->filter(fn (int $id, string $needle) => str_contains($normalized, $needle))
            ->values()
            ->unique()
            ->all();
    }

    protected function locationId(Property $property, array $settings): ?string
    {
        $configured = trim((string) ($settings['location_id'] ?? ''));
        if (Str::isUuid($configured)) {
            return $configured;
        }
        if (! $property->exists || ! $property->neighborhood_id || ! Schema::hasTable('portal_mappings')) {
            return null;
        }

        $integrationId = Integration::where('slug', 'fincaraiz')->value('id');
        $externalId = $integrationId ? PortalMapping::where([
            'integration_id' => $integrationId,
            'mappable_type' => Neighborhood::class,
            'mappable_id' => $property->neighborhood_id,
        ])->value('external_id') : null;

        return Str::isUuid((string) $externalId) ? (string) $externalId : null;
    }

    protected function offer(Property $property, array $settings): string
    {
        $slug = $this->normalize($property->transactionType?->slug ?? $property->transactionType?->name);
        if ((str_contains($slug, 'venta') || str_contains($slug, 'sale'))
            && (str_contains($slug, 'arriendo') || str_contains($slug, 'rent'))) {
            $preferred = strtolower((string) ($settings['dual_offer'] ?? 'sale'));

            return $preferred === 'rent' && (float) $property->rent_price > 0 ? 'rent' : 'sell';
        }
        if (str_contains($slug, 'vacacional') || str_contains($slug, 'lease')) {
            return 'lease';
        }
        if (str_contains($slug, 'arriendo') || str_contains($slug, 'rent') || str_contains($slug, 'alquiler')) {
            return 'rent';
        }

        return 'sell';
    }

    protected function propertyType(?string $value): string
    {
        $slug = Str::slug((string) $value);

        return [
            'lote' => 'lot',
            'lote-urbano' => 'lot',
            'local' => 'commercial',
            'oficina' => 'office',
            'bodega' => 'warehouse',
            'finca' => 'farm',
            'apartamento' => 'apartment',
            'casa' => 'house',
            'habitacion' => 'room',
            'consultorio' => 'consulting-room',
            'edificio' => 'building',
            'cabana' => 'cabin',
            'casa-campestre' => 'country-house',
            'apartaestudio' => 'studio',
            'casa-lote' => 'house-lot',
            'parqueadero' => 'parking',
        ][$slug] ?? '';
    }

    protected function condition(?string $value): int
    {
        return match ($this->normalize($value)) {
            'new', 'nuevo', 'nueva' => 1,
            'used', 'usado', 'usada' => 3,
            'remodeled', 'remodelado', 'remodelada' => 4,
            'under construction', 'under-construction', 'en construccion' => 6,
            default => 0,
        };
    }

    protected function age($years): int
    {
        $years = max(0, (int) $years);

        return match (true) {
            $years === 0 => 0,
            $years < 1 => 1,
            $years <= 8 => 2,
            $years <= 15 => 3,
            $years <= 30 => 4,
            default => 5,
        };
    }

    protected function stratum($value): int
    {
        $value = (int) $value;

        return in_array($value, [0, 1, 2, 3, 4, 5, 6, 100, 110], true) ? $value : 0;
    }

    protected function localProperty(stdClass $row): Property
    {
        $cityName = $this->text($row->ciudad ?? 'Ciudad sin homologar');
        $department = $this->department($row) ?: 'Colombia';
        $city = City::firstOrCreate(
            ['name' => $cityName, 'department' => $department],
            [
                'dane_code' => '8'.substr((string) sprintf('%u', crc32($department.'|'.$cityName)), 0, 7),
                'country_code' => 'CO',
                'lat' => $this->number($row->latitud ?? null),
                'lng' => $this->number($row->longitud ?? null),
                'active' => true,
            ]
        );
        $neighborhoodName = $this->text($row->barrio ?? '');
        $neighborhood = $neighborhoodName !== '' ? Neighborhood::firstOrCreate(
            ['city_id' => $city->id, 'name' => $neighborhoodName],
            [
                'postal_code' => $this->postalCode($row),
                'lat' => $this->number($row->latitud ?? null),
                'lng' => $this->number($row->longitud ?? null),
                'active' => true,
            ]
        ) : null;
        $type = PropertyType::firstOrCreate(
            ['slug' => Str::slug((string) ($row->tipo_inmueble ?: 'inmueble'))],
            ['name' => $row->tipo_inmueble ?: 'Inmueble', 'active' => true]
        );
        $transactionSlug = $this->transactionSlug($row->tipo_negocio ?? null);
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
        $advisor = $this->sourceAdvisor($row);

        return Property::updateOrCreate(
            ['code' => trim((string) $row->codigo)],
            [
                'title' => trim(($row->tipo_inmueble ?: 'Inmueble').' en '.($row->tipo_negocio ?: 'gestión')),
                'description' => $this->sourceDescription($row),
                'condition' => 'used',
                'city_id' => $city->id,
                'neighborhood_id' => $neighborhood?->id,
                'address' => $this->text($row->direccion ?? $row->direccion_fisica ?? $row->barrio),
                'address_extra' => $this->text($row->direccion_fisica ?? ''),
                'lat' => $this->number($row->latitud ?? null),
                'lng' => $this->number($row->longitud ?? null),
                'show_exact_address' => (bool) config('portals.fincaraiz.show_exact_address', false),
                'property_type_id' => $type->id,
                'transaction_type_id' => $transaction->id,
                'sale_price' => $this->money($row->precio_venta ?? null),
                'rent_price' => $this->money($row->precio_arriendo ?? null),
                'admin_price' => $this->money($row->precio_admin ?? null),
                'currency' => 'COP',
                'price_negotiable' => $this->yesNo($row->negociable ?? null),
                'area_total' => $this->number(($row->area_construida ?? null) ?: ($row->area_terreno ?? null)),
                'area_built' => $this->number($row->area_construida ?? null),
                'area_private' => $this->number($row->area_privada ?? null),
                'area_land' => $this->number($row->area_terreno ?? null),
                'bedrooms' => $this->positiveInteger($row->habitaciones ?? null) ?? 0,
                'bathrooms' => $this->positiveInteger($row->banos ?? null) ?? 0,
                'parking_spaces' => $this->positiveInteger($row->parqueaderos ?? null) ?? 0,
                'age_years' => $this->positiveInteger($row->edad ?? null) ?? 0,
                'stratum' => $this->stratum($row->estrato ?? null),
                'furnished' => $this->yesNo($row->amoblado ?? null),
                'status' => $this->localStatus($row->estado ?? null),
                'contact_name' => $this->text($advisor?->nombre ?? $row->funcionario ?? ''),
                'contact_phone' => $this->text($advisor?->celular ?? ''),
                'contact_whatsapp' => $this->text($advisor?->celular ?? ''),
                'contact_email' => filter_var($advisor?->correo ?? null, FILTER_VALIDATE_EMAIL) ? $advisor->correo : null,
            ]
        );
    }

    protected function sourceAdvisor(stdClass $row): ?stdClass
    {
        if (! ($row->id_funcionario ?? null)) {
            return null;
        }

        return DB::connection('wordpress')->table('wp_jet_cct_funcionarios')
            ->where('id_empleado', $row->id_funcionario)
            ->orWhere('_ID', $row->id_funcionario)
            ->first();
    }

    protected function postalCode(stdClass $row): ?string
    {
        if (! ($row->barrio ?? null) || ! ($row->ciudad ?? null)) {
            return null;
        }

        return DB::connection('wordpress')->table('wp_jet_cct_barrios')
            ->whereRaw('LOWER(TRIM(barrio)) = ?', [strtolower(trim((string) $row->barrio))])
            ->whereRaw('LOWER(TRIM(ciudad)) = ?', [strtolower(trim((string) $row->ciudad))])
            ->value('codigo_postal');
    }

    protected function department(stdClass $row): string
    {
        return $this->text(DB::connection('wordpress')->table('wp_jet_cct_barrios')
            ->whereRaw('LOWER(TRIM(barrio)) = ?', [strtolower(trim((string) ($row->barrio ?? '')))])
            ->whereRaw('LOWER(TRIM(ciudad)) = ?', [strtolower(trim((string) ($row->ciudad ?? '')))])
            ->value('departamento'));
    }

    protected function sourceDescription(stdClass $row): string
    {
        return $this->text(($row->descripcion ?? null) ?: ($row->datos_adicionales ?? null) ?: ($row->punto_referencia ?? null));
    }

    protected function description(Property $property): string
    {
        $description = strip_tags((string) ($property->description ?: $property->title));
        $description = preg_replace('/https?:\/\/\S+|www\.\S+/i', '', $description);
        $description = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', '', $description);
        $description = preg_replace('/(?:\+?57\s*)?(?:\d[\s.-]*){10,}/', '', $description);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $description)), 49000, '');
    }

    protected function transactionSlug(?string $value): string
    {
        $slug = $this->normalize($value);
        if ((str_contains($slug, 'venta') || str_contains($slug, 'sale'))
            && (str_contains($slug, 'arriendo') || str_contains($slug, 'rent'))) {
            return 'sale_rent';
        }

        return str_contains($slug, 'arriendo') || str_contains($slug, 'rent') || str_contains($slug, 'alquiler')
            ? 'rent'
            : 'sale';
    }

    protected function localStatus(?string $status): string
    {
        return match ($this->normalize($status)) {
            'publico' => 'active',
            'arrendado' => 'rented',
            'vendido' => 'sold',
            'en borrador' => 'draft',
            'ocupado', 'no publicar' => 'paused',
            'en cierre', 'en proceso de cierre' => 'reserved',
            default => 'archived',
        };
    }

    protected function bucket(int $value, int $max, int $overflow): int
    {
        return $value > $max ? $overflow : max(0, $value);
    }

    protected function phone($value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '57')) {
            return '+'.$digits;
        }

        return '+57'.$digits;
    }

    protected function normalize(?string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii((string) $value))));
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

    protected function money($value): ?float
    {
        $clean = trim((string) $value);
        if ($clean === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9,.\-]/', '', $clean);
        if (preg_match('/^\d{1,3}([,.]\d{3})+$/', $clean)) {
            $clean = str_replace([',', '.'], '', $clean);
        } elseif (str_contains($clean, ',') && str_contains($clean, '.')) {
            $decimalSeparator = strrpos($clean, ',') > strrpos($clean, '.') ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $clean = str_replace($thousandsSeparator, '', $clean);
            $clean = str_replace($decimalSeparator, '.', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    protected function positiveInteger($value): ?int
    {
        $clean = preg_replace('/[^0-9]/', '', (string) $value);

        return $clean !== '' ? (int) $clean : null;
    }

    protected function yesNo($value): bool
    {
        return in_array($this->normalize((string) $value), ['si', '1', 'true', 'yes'], true);
    }
}
