<?php

namespace App\Services\Portals;

use App\Models\City;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\TransactionType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;

class ProppitPropertyMapper
{
    public function fromCode(string $code): array
    {
        $row = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->where('codigo', $code)
            ->first();

        abort_unless($row, 404, 'Propiedad no encontrada en wp_jet_cct_inmuebles.');

        $payload = $this->payload($row);

        return [
            'payload' => $payload,
            'errors' => $this->validatePayload($payload),
            'property' => $this->localProperty($row, $payload),
            'source' => [
                'code' => (string) $row->codigo,
                'city' => $row->ciudad,
                'neighborhood' => $row->barrio,
                'image_count' => count($payload['multimedia']['pictures'] ?? []),
            ],
        ];
    }

    public function referenceId(string $code): string
    {
        return trim($code);
    }

    protected function payload(stdClass $row): array
    {
        $description = $this->text(($row->descripcion ?? null) ?: $row->datos_adicionales ?: $row->punto_referencia);
        $title = $this->propertyName($row);
        $area = (float) ($this->number($row->area_construida) ?? $this->number($row->area_terreno) ?? 0);
        $pictures = collect($this->media($row))->map(fn (string $url) => ['url' => $url])->values()->all();
        $videos = collect($this->videos($row))->map(fn (string $url) => ['url' => $url])->values()->all();
        $operations = $this->operations($row);
        $propertyType = $this->propertyType($row->tipo_inmueble);
        $stratum = (int) ($this->integer($row->estrato) ?? 0);
        $publisherId = (string) config('portals.proppit.publisher_external_id');
        $phone = $this->phone(config('portals.proppit.default_contact_phone'));
        $email = trim((string) config('portals.proppit.default_contact_email'));
        $amenities = $this->amenities($row, $propertyType);
        $nearbyLocations = $this->nearbyLocations($row, $propertyType);
        $rules = $this->rules($row, $propertyType);

        return array_filter([
            'referenceId' => $this->referenceId((string) $row->codigo),
            'publisher' => ['externalId' => $publisherId],
            'contact' => array_filter([
                'name' => $this->text(config('portals.proppit.default_contact_name')),
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'phone' => $phone,
                'whatsapp' => $phone,
            ]),
            'property' => array_filter([
                'type' => $propertyType,
                'location' => array_filter([
                    'countryCode' => (string) config('portals.proppit.country', 'CO'),
                    'visibility' => (string) config('portals.proppit.location_visibility', 'approximate'),
                    'geo' => array_values(array_filter([
                        $row->ciudad ? ['name' => $this->text($row->ciudad), 'level' => 'locality'] : null,
                        $row->barrio ? ['name' => $this->text($row->barrio), 'level' => 'neighborhood'] : null,
                    ])),
                    'coordinates' => [
                        'lat' => $this->number($row->latitud),
                        'long' => $this->number($row->longitud),
                    ],
                    'address' => $this->text($row->direccion_fisica ?: $row->direccion ?: $row->barrio),
                    'postcode' => $this->postalCode($row),
                    'nearbyLocations' => $nearbyLocations,
                ]),
                'communityFees' => $this->money($row->precio_admin) ? [
                    'value' => (float) $this->money($row->precio_admin),
                    'currency' => 'COP',
                ] : null,
                'project' => $this->text($row->copropiedad) ? ['name' => $this->text($row->copropiedad)] : null,
            ]),
            'operations' => $operations,
            'title' => ['locale' => 'es-CO', 'text' => $title],
            'description' => ['locale' => 'es-CO', 'text' => $description],
            'multimedia' => array_filter([
                'pictures' => $pictures,
                'videos' => $videos,
            ], fn ($value) => $value !== []),
            'totalArea' => $area > 0 ? ['value' => $area, 'unit' => 'sqm'] : null,
            'floorArea' => $propertyType !== 'land' && $area > 0 ? ['value' => $area, 'unit' => 'sqm'] : null,
            'usableArea' => $this->number($row->area_privada) ? ['value' => (float) $this->number($row->area_privada), 'unit' => 'sqm'] : null,
            'isBoosted' => $this->isBoosted($row),
            'isExclusive' => false,
            'bedrooms' => (int) ($this->integer($row->habitaciones) ?? 0),
            'bathrooms' => (int) ($this->integer($row->banos) ?? 0),
            'stratum' => $stratum >= 1 && $stratum <= 7 ? $stratum : null,
            'parkingSpaces' => (int) ($this->integer($row->parqueaderos) ?? 0),
            'condition' => 'second hand',
            'furnished' => $this->yesNo($row->amoblado) ? 'fully' : 'unfurnished',
            'rules' => $rules,
            'amenities' => $amenities,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    protected function validatePayload(array $payload): array
    {
        $errors = [];

        foreach (['referenceId', 'publisher', 'property', 'operations', 'title', 'description'] as $field) {
            if (empty($payload[$field])) {
                $errors[] = "Falta {$field}.";
            }
        }

        if (empty(config('portals.proppit.user')) || empty(config('portals.proppit.password'))) {
            $errors[] = 'Configura PROPPIT_CLIENT_ID y PROPPIT_CLIENT_SECRET en .env.';
        }

        if (empty(config('portals.proppit.publisher_external_id'))) {
            $errors[] = 'Configura PROPPIT_PUBLISHER_EXTERNAL_ID en .env.';
        }

        if (empty($payload['contact']['email'] ?? null)) {
            $errors[] = 'Falta email de contacto válido.';
        }

        if (empty($payload['property']['location']['coordinates']['lat'] ?? null) || empty($payload['property']['location']['coordinates']['long'] ?? null)) {
            $errors[] = 'Faltan coordenadas.';
        }

        if (empty($payload['multimedia']['pictures'])) {
            $errors[] = 'Proppit requiere al menos una foto.';
        }

        if (($payload['property']['type'] ?? null) === 'land' && empty($payload['totalArea'])) {
            $errors[] = 'Proppit requiere totalArea para lotes.';
        }

        if (($payload['property']['type'] ?? null) !== 'land' && empty($payload['floorArea'])) {
            $errors[] = 'Proppit requiere floorArea para este tipo de inmueble.';
        }

        if (strlen((string) ($payload['description']['text'] ?? '')) < 20) {
            $errors[] = 'La descripción debe tener mínimo 20 caracteres.';
        }

        return $errors;
    }

    protected function localProperty(stdClass $row, array $payload): Property
    {
        $city = City::firstOrCreate(
            ['dane_code' => str_pad((string) config('portals.ciencuadras.default_city_id', '00000000'), 8, '0', STR_PAD_LEFT)],
            ['name' => $row->ciudad ?: 'Ciudad sin homologar', 'department' => 'CO', 'country_code' => 'CO', 'active' => true]
        );
        $propertyType = PropertyType::firstOrCreate(
            ['slug' => Str::slug($row->tipo_inmueble ?: 'inmueble')],
            ['name' => $row->tipo_inmueble ?: 'Inmueble', 'active' => true]
        );
        $transactionType = TransactionType::firstOrCreate(
            ['slug' => $this->transactionSlug($row->tipo_negocio)],
            ['name' => $row->tipo_negocio ?: 'Venta o arriendo', 'has_sale_price' => true, 'has_rent_price' => true, 'has_admin_price' => true, 'active' => true]
        );

        return Property::updateOrCreate(
            ['code' => (string) $row->codigo],
            [
                'title' => $payload['title']['text'],
                'description' => $payload['description']['text'],
                'condition' => 'used',
                'city_id' => $city->id,
                'address' => $payload['property']['location']['address'] ?? null,
                'lat' => $payload['property']['location']['coordinates']['lat'] ?? null,
                'lng' => $payload['property']['location']['coordinates']['long'] ?? null,
                'show_exact_address' => config('portals.proppit.location_visibility', 'approximate') === 'accurate',
                'property_type_id' => $propertyType->id,
                'transaction_type_id' => $transactionType->id,
                'sale_price' => $this->money($row->precio_venta),
                'rent_price' => $this->money($row->precio_arriendo),
                'admin_price' => $this->money($row->precio_admin),
                'currency' => 'COP',
                'area_total' => $payload['totalArea']['value'] ?? null,
                'area_built' => $payload['floorArea']['value'] ?? null,
                'area_private' => $payload['usableArea']['value'] ?? null,
                'bedrooms' => $payload['bedrooms'] ?? 0,
                'bathrooms' => $payload['bathrooms'] ?? 0,
                'parking_spaces' => $payload['parkingSpaces'] ?? 0,
                'stratum' => min(6, (int) ($payload['stratum'] ?? 0)),
                'furnished' => ($payload['furnished'] ?? '') === 'fully',
                'status' => 'active',
                'contact_name' => $payload['contact']['name'] ?? null,
                'contact_phone' => $payload['contact']['phone'] ?? null,
                'contact_whatsapp' => $payload['contact']['whatsapp'] ?? null,
                'contact_email' => $payload['contact']['email'] ?? null,
            ]
        );
    }

    protected function consultant(stdClass $row): ?stdClass
    {
        if (! $row->id_funcionario) {
            return null;
        }

        return DB::connection('wordpress')
            ->table('wp_jet_cct_funcionarios')
            ->where('id_empleado', $row->id_funcionario)
            ->orWhere('_ID', $row->id_funcionario)
            ->first();
    }

    protected function isBoosted(stdClass $row): bool
    {
        if ($this->yesNo($row->proppit_promocionado ?? null) || $this->yesNo($row->promocion_premium ?? null)) {
            return true;
        }

        $limit = max(0, (int) config('portals.proppit.boosted_weekly_limit', 0));
        if ($limit === 0) {
            return false;
        }

        return in_array((string) $row->codigo, $this->weeklyBoostedCodes($limit), true);
    }

    public function boostedCodes(?int $limit = null): array
    {
        $limit = max(0, (int) ($limit ?? config('portals.proppit.boosted_weekly_limit', 0)));

        return $limit > 0 ? $this->weeklyBoostedCodes($limit) : [];
    }

    public function boostOnPublish(array $payload): array
    {
        if (! config('portals.proppit.boost_new_listings', true)) {
            return $payload;
        }

        $limit = max(0, (int) config('portals.proppit.boosted_weekly_limit', 1));
        if ($limit === 0) {
            return $payload;
        }

        $payload['isBoosted'] = true;

        return $payload;
    }

    protected function weeklyBoostedCodes(int $limit): array
    {
        $week = now()->format('o-W');
        $cacheKey = "proppit.boosted-codes.{$week}.{$limit}";

        return Cache::remember($cacheKey, now()->endOfWeek(), function () use ($limit, $week): array {
            return DB::connection('wordpress')
                ->table('wp_jet_cct_inmuebles')
                ->where('cct_status', 'publish')
                ->whereNotNull('codigo')
                ->pluck('codigo')
                ->map(fn ($code) => trim((string) $code))
                ->filter()
                ->unique()
                ->sortBy(fn (string $code) => hash('sha256', $week.'|'.$code))
                ->take($limit)
                ->values()
                ->all();
        });
    }

    protected function amenities(stdClass $row, string $propertyType): array
    {
        $values = [
            'disabled access' => ['acceso discapacitados', 'discapacitados'],
            'water' => ['agua', 'servicio de agua'],
            'air conditioning' => ['aire acondicionado'],
            'alarm' => ['alarma'],
            'car park' => ['aparcadero', 'parqueadero', 'garaje', 'garage'],
            "children's area" => ['area infantil', 'área infantil', 'parque infantil', 'zona infantil'],
            'lift' => ['ascensor', 'elevador'],
            'balcony' => ['balcon', 'balcón'],
            'grill' => ['bbq', 'barbecue', 'asador'],
            'heating' => ['calefaccion', 'calefacción'],
            'tennis court' => ['cancha de tenis', 'tenis'],
            'guardhouse' => ['caseta de vigilancia', 'vigilancia', 'portería', 'porteria'],
            'fireplace' => ['chimenea'],
            'integral kitchen' => ['cocina integral'],
            'service room' => ['cuarto de servicio', 'habitacion de servicio', 'habitación de servicio'],
            'natural gas' => ['gas natural'],
            'gym' => ['gimnasio', 'gym'],
            'internet' => ['internet'],
            'jacuzzi' => ['jacuzzi'],
            'garden' => ['jardin', 'jardín'],
            'yard' => ['patio'],
            'swimming pool' => ['piscina'],
            'security' => ['seguridad privada', 'puerta de seguridad', 'vigilancia'],
            'sauna' => ['sauna'],
            'water tank' => ['tanque de agua'],
            'terrace' => ['terraza'],
            'panoramic view' => ['vista panoramica', 'vista panorámica'],
            'wi-fi' => ['wi-fi', 'wifi'],
            'electricity' => ['electricidad', 'luz'],
        ];

        return $this->matchedValues($row, $values, $this->allowedValues($propertyType, 'amenities'));
    }

    protected function nearbyLocations(stdClass $row, string $propertyType): array
    {
        $values = [
            'main street' => ['avenida', 'via principal', 'vía principal', 'calle principal'],
            'shopping mall' => ['centro comercial', 'centros comerciales'],
            'school' => ['colegio', 'colegios', 'universidad'],
            'sea' => ['mar', 'playa'],
            'train station' => ['estacion de tren', 'estación de tren', 'transcaribe', 'estacion'],
            'park' => ['parque', 'parques'],
        ];

        return $this->matchedValues($row, $values, $this->allowedValues($propertyType, 'nearbyLocations'));
    }

    protected function rules(stdClass $row, string $propertyType): array
    {
        $values = [
            'pets allowed' => ['mascotas permitidas', 'acepta mascotas', 'pet friendly'],
            'children allowed' => ['permite ninos', 'permite niños', 'niños', 'ninos'],
            'only families' => ['solo familias', 'familias'],
        ];

        return $this->matchedValues($row, $values, $this->allowedValues($propertyType, 'rules'));
    }

    protected function matchedValues(stdClass $row, array $values, array $allowed): array
    {
        $haystack = $this->searchText($row);

        return collect($values)
            ->filter(fn (array $needles, string $value) => in_array($value, $allowed, true)
                && collect($needles)->contains(fn (string $needle) => str_contains($haystack, $this->normalize($needle))))
            ->keys()
            ->values()
            ->all();
    }

    protected function searchText(stdClass $row): string
    {
        return $this->normalize(implode(' ', array_filter([
            $row->interiores ?? null,
            $row->exteriores ?? null,
            $row->alrededores ?? null,
            $row->zonas_sociales ?? null,
            $row->servicios_publicos ?? null,
            $row->descripcion ?? null,
            $row->datos_adicionales ?? null,
            $row->punto_referencia ?? null,
            $this->yesNo($row->luz ?? null) ? 'luz electricidad' : null,
            $this->yesNo($row->agua ?? null) ? 'agua' : null,
            $this->yesNo($row->gas ?? null) ? 'gas natural' : null,
            $this->yesNo($row->vigilancia ?? null) ? 'vigilancia seguridad privada' : null,
            $this->yesNo($row->parqueadero ?? null) || (int) ($this->integer($row->parqueaderos ?? null) ?? 0) > 0 ? 'parqueadero aparcadero' : null,
        ])));
    }

    protected function allowedValues(string $propertyType, string $kind): array
    {
        $common = [
            'nearbyLocations' => ['main street', 'shopping mall', 'train station', 'park', 'school', 'sea'],
            'amenities' => ['air conditioning', 'alarm', 'balcony', 'car park', 'cellar', 'electricity', 'equipped kitchen', 'fireplace', 'heating', 'integral kitchen', 'internet', 'jacuzzi', 'natural gas', 'office', 'panoramic view', 'service room', 'terrace', 'water', 'water tank', 'yard', "children's area", 'concierge', 'disabled access', 'garden', 'grill', 'guardhouse', 'gym', 'library', 'lift', 'sauna', 'security', 'swimming pool', 'tennis court', 'wi-fi'],
            'rules' => ['pets allowed', 'children allowed', 'only families'],
        ];

        $restricted = [
            'land' => [
                'nearbyLocations' => ['main street'],
                'amenities' => ['car park', 'cellar', 'electricity', 'natural gas', 'water tank', 'guardhouse', 'security'],
                'rules' => [],
            ],
            'commercial' => [
                'nearbyLocations' => ['main street', 'shopping mall', 'park'],
                'rules' => [],
            ],
            'office' => [
                'nearbyLocations' => ['main street', 'shopping mall', 'park', 'school'],
                'rules' => [],
            ],
            'industrial unit' => [
                'nearbyLocations' => ['main street'],
                'amenities' => ['alarm', 'car park', 'cellar', 'electricity', 'heating', 'water', 'water tank', "children's area", 'concierge', 'disabled access', 'guardhouse', 'gym', 'lift', 'security', 'swimming pool'],
                'rules' => [],
            ],
        ];

        return $restricted[$propertyType][$kind] ?? $common[$kind] ?? [];
    }

    protected function operations(stdClass $row): array
    {
        $slug = Str::slug($row->tipo_negocio ?? '');
        $hasSell = str_contains($slug, 'venta');
        $hasRent = str_contains($slug, 'arriendo') || str_contains($slug, 'renta');
        $rentPrice = $this->money($row->precio_arriendo);
        $salePrice = $this->money($row->precio_venta);

        if ($hasSell && $hasRent && $rentPrice) {
            return [['type' => 'rent', 'price' => ['value' => (float) $rentPrice, 'currency' => 'COP']]];
        }

        $operations = [];
        if ($hasSell && $salePrice) {
            $operations[] = ['type' => 'sell', 'price' => ['value' => (float) $salePrice, 'currency' => 'COP']];
        }
        if ($hasRent && $rentPrice) {
            $operations[] = ['type' => 'rent', 'price' => ['value' => (float) $rentPrice, 'currency' => 'COP']];
        }

        return $operations;
    }

    protected function propertyType(?string $type): string
    {
        $slug = Str::slug($type ?? '');
        return [
            'apartamento' => 'apartment',
            'apartaestudio' => 'apartment',
            'casa' => 'house',
            'casa-campestre' => 'house',
            'finca' => 'villa',
            'oficina' => 'office',
            'consultorio' => 'office',
            'bodega' => 'industrial unit',
            'local' => 'commercial',
            'lote' => 'land',
            'edificio' => 'commercial',
        ][$slug] ?? 'apartment';
    }

    protected function transactionSlug(?string $type): string
    {
        $slug = Str::slug($type ?? '');
        if (str_contains($slug, 'venta') && str_contains($slug, 'arriendo')) {
            return 'sale_rent';
        }
        return str_contains($slug, 'arriendo') || str_contains($slug, 'renta') ? 'rent' : 'sale';
    }

    protected function media(stdClass $row): array
    {
        $ids = collect([$row->foto_portada, ...explode(',', (string) $row->galeria)])
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->take(30)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $images = DB::connection('wordpress')
            ->table('wp_posts')
            ->whereIn('ID', $ids)
            ->pluck('guid', 'ID');

        return $ids->map(fn (int $id) => $images[$id] ?? null)->filter()->values()->all();
    }

    protected function videos(stdClass $row): array
    {
        return collect(preg_split('/[\r\n,]+/', (string) ($row->video ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $url) => trim(strip_tags($url)))
            ->map(fn (string $url) => str_starts_with($url, 'http://') ? 'https://'.substr($url, 7) : $url)
            ->filter(fn (string $url) => filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https://~i', $url))
            ->unique()
            ->values()
            ->all();
    }

    protected function postalCode(stdClass $row): ?string
    {
        if (! Schema::connection('wordpress')->hasColumn('wp_jet_cct_barrios', 'codigo_postal')) {
            return null;
        }

        return DB::connection('wordpress')
            ->table('wp_jet_cct_barrios')
            ->whereRaw('LOWER(TRIM(barrio)) = ?', [strtolower(trim((string) $row->barrio))])
            ->whereRaw('LOWER(TRIM(ciudad)) = ?', [strtolower(trim((string) $row->ciudad))])
            ->value('codigo_postal');
    }

    protected function propertyName(stdClass $row): string
    {
        return trim(($row->tipo_inmueble ?: 'Inmueble') . ' en ' . ($row->tipo_negocio ?: 'gestión') . ($row->barrio ? ' - ' . $row->barrio : ''));
    }

    protected function text(?string $value): string
    {
        return trim(strip_tags((string) $value));
    }

    protected function normalize(?string $value): string
    {
        return Str::ascii(mb_strtolower($this->text($value), 'UTF-8'));
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

    protected function money($value): ?int
    {
        $clean = preg_replace('/[^0-9]/', '', (string) $value);
        return $clean !== '' ? (int) $clean : null;
    }

    protected function yesNo($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['si', 'sí', '1', 'true', 'yes'], true);
    }

    protected function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return config('portals.proppit.default_contact_phone');
        }

        return str_starts_with($digits, '57') ? '+' . $digits : '+57' . $digits;
    }
}
