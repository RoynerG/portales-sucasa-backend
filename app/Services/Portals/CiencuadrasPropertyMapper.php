<?php

namespace App\Services\Portals;

use App\Models\City;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\TransactionType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;

class CiencuadrasPropertyMapper
{
    public function fromCode(string $code, string $status = 'A'): array
    {
        $row = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->where('codigo', $code)
            ->first();

        abort_unless($row, 404, 'Propiedad no encontrada en wp_jet_cct_inmuebles.');

        $consultant = $this->consultant($row);
        $payload = $this->payload($row, $consultant, $status);

        return [
            'payload' => $payload,
            'errors' => $this->validatePayload($payload, $status),
            'property' => $this->localProperty($row, $payload),
            'source' => [
                'code' => (string) $row->codigo,
                'city' => $row->ciudad,
                'neighborhood' => $row->barrio,
                'image_count' => count($payload['features']['photospropertyData'] ?? []),
            ],
        ];
    }

    public function externalCode(string $code): string
    {
        return (string) config('portals.ciencuadras.property_code_prefix') . $code;
    }

    protected function payload(stdClass $row, ?stdClass $consultant, string $status): array
    {
        $typeId = $this->propertyTypeId($row->tipo_inmueble);
        $transactionId = $this->transactionTypeId($row->tipo_negocio);
        $description = $this->description($row);
        $images = $this->media($row);
        $localityId = $this->localityId($row);

        $payload = [
            'cityId' => $this->cityId($row),
            'neighborhoodName' => $this->text($row->barrio),
            'propertyTypeId' => $typeId,
            'transactionTypeId' => $transactionId,
            'address' => $this->text($row->direccion ?: $row->direccion_fisica ?: $row->barrio),
            'showAddress' => 1,
            'stratum' => max(0, min(8, (int) ($this->integer($row->estrato) ?? 0))),
            'propertyCode' => $this->externalCode((string) $row->codigo),
            'latitude' => $this->number($row->latitud),
            'longitude' => $this->number($row->longitud),
            'sellingPrice' => $this->sellingPrice($row, $transactionId),
            'leasingFee' => $this->leasingFee($row, $transactionId),
            'additionalInfo' => $description,
            'status' => $status,
            'integrator' => (string) config('portals.ciencuadras.integrator'),
            'advisorName' => $this->text($consultant->nombre ?? $row->funcionario ?? 'Sucasa Inmobiliaria'),
            'advisorPhone' => $this->phone($consultant->celular ?? null),
            'advisorMail' => filter_var($consultant->correo ?? null, FILTER_VALIDATE_EMAIL) ? $consultant->correo : null,
            'advisorWhatsapp' => $this->phone($consultant->celular ?? null),
            'features' => [
                'numBedRooms' => (int) ($this->integer($row->habitaciones) ?? 0),
                'numBathrooms' => (int) ($this->integer($row->banos) ?? 0),
                'numParking' => (int) ($this->integer($row->parqueaderos) ?? 0),
                'parkingType' => $this->parkingType($row->tipo_parqueadero ?: $row->parqueadero),
                'antiquity' => (int) ($this->integer($row->edad) ?? 0),
                'propertyName' => $this->propertyName($row),
                'remodeled' => false,
                'project' => $this->yesNo($row->pertenece_copropiedad),
                'projectName' => $this->text($row->copropiedad),
                'administrationValue' => (int) ($this->money($row->precio_admin) ?? 0),
                'serviceRoom' => $this->hasFeature($row, ['cuarto de servicio', 'habitacion de servicio']),
                'serviceBathroom' => $this->hasFeature($row, ['bano de servicio', 'baño de servicio']),
                'laundryZone' => $this->hasFeature($row, ['zona de labores', 'zona de ropas', 'lavanderia', 'lavandería']),
                'airConditioner' => $this->hasFeature($row, ['aire acondicionado']),
                'terracesNumber' => '',
                'terraceArea' => '',
                'balconiesNumber' => $this->hasFeature($row, ['balcon', 'balcón']) ? 1 : 0,
                'depositsNumber' => (int) ($this->integer($row->depositos ?: $row->deposito) ?? 0),
                'floorsNumber' => 0,
                'elevatorsNumber' => $this->hasFeature($row, ['ascensor']) ? 1 : 0,
                'vigilance' => $this->yesNo($row->vigilancia) ? 2 : 1,
                'reception' => $this->hasFeature($row, ['recepcion', 'recepción', 'porteria', 'portería', 'lobby']),
                'closedCircuitTv' => $this->hasFeature($row, ['circuito cerrado', 'cctv']),
                'electricPlant' => $this->hasFeature($row, ['planta electrica', 'planta eléctrica']),
                'childrenZone' => $this->hasFeature($row, ['parque infantil', 'zona infantil']),
                'greenZones' => $this->hasFeature($row, ['zonas verdes', 'zona verde']),
                'communalPool' => $this->hasFeature($row, ['piscina']),
                'gym' => $this->hasFeature($row, ['gimnasio', 'gym']),
                'socialVenue' => $this->hasFeature($row, ['salon social', 'salón social']),
                'brandNew' => false,
                'addressComplement' => $this->text($row->direccion_fisica),
                'furnished' => $this->yesNo($row->amoblado),
                'inConstruction' => 2,
                'builtArea' => (int) ($this->number($row->area_construida) ?? $this->number($row->area_terreno) ?? 0),
                'privateArea' => (int) ($this->number($row->area_privada) ?? $this->number($row->area_construida) ?? 0),
                'area' => (int) ($this->number($row->area_construida) ?? $this->number($row->area_terreno) ?? 0),
                'photospropertyData' => $images,
            ],
        ];

        if ($payload['advisorMail'] === null) {
            unset($payload['advisorMail']);
        }

        if ($localityId) {
            $payload['localityId'] = $localityId;
        }

        if ($realestateEnrollment = $this->text($row->matricula_inmobiliaria)) {
            $payload['realestateEnrollment'] = $realestateEnrollment;
        }

        return $payload;
    }

    protected function validatePayload(array $payload, string $status): array
    {
        $errors = [];

        foreach (['cityId', 'propertyTypeId', 'transactionTypeId', 'address', 'propertyCode', 'additionalInfo', 'integrator'] as $field) {
            if (($payload[$field] ?? null) === null || $payload[$field] === '' || $payload[$field] === 0) {
                $errors[] = "Falta {$field}.";
            }
        }

        if (strlen((string) $payload['propertyCode']) > 20) {
            $errors[] = 'propertyCode supera 20 caracteres; ajusta CIENCUADRAS_PROPERTY_CODE_PREFIX.';
        }

        if (strlen((string) $payload['additionalInfo']) < 35) {
            $errors[] = 'additionalInfo debe tener mínimo 35 caracteres.';
        }

        if (! is_numeric($payload['latitude']) || ! is_numeric($payload['longitude'])) {
            $errors[] = 'Faltan latitude/longitude numéricos.';
        }

        if ($status === 'A') {
            if (($payload['sellingPrice'] ?? 0) <= 0 && ($payload['leasingFee'] ?? 0) <= 0) {
                $errors[] = 'Debe existir precio de venta o arriendo.';
            }

            if (count($payload['features']['photospropertyData'] ?? []) < 3) {
                $errors[] = 'Ciencuadras requiere mínimo 3 fotos para activar el inmueble.';
            }
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

        $txSlug = match ((int) $payload['transactionTypeId']) {
            1 => 'sale',
            2 => 'rent',
            default => 'sale_rent',
        };
        $transactionType = TransactionType::firstOrCreate(
            ['slug' => $txSlug],
            [
                'name' => $row->tipo_negocio ?: 'Venta o arriendo',
                'has_sale_price' => in_array((int) $payload['transactionTypeId'], [1, 3], true),
                'has_rent_price' => in_array((int) $payload['transactionTypeId'], [2, 3], true),
                'has_admin_price' => true,
                'active' => true,
            ]
        );

        return Property::updateOrCreate(
            ['code' => (string) $row->codigo],
            [
                'title' => $this->propertyName($row),
                'description' => $payload['additionalInfo'],
                'condition' => 'used',
                'city_id' => $city->id,
                'address' => $payload['address'],
                'lat' => $payload['latitude'],
                'lng' => $payload['longitude'],
                'show_exact_address' => true,
                'property_type_id' => $propertyType->id,
                'transaction_type_id' => $transactionType->id,
                'sale_price' => $this->money($row->precio_venta),
                'rent_price' => $this->money($row->precio_arriendo),
                'admin_price' => $this->money($row->precio_admin),
                'currency' => 'COP',
                'area_total' => $payload['features']['area'],
                'area_built' => $payload['features']['builtArea'],
                'area_private' => $payload['features']['privateArea'],
                'area_land' => $this->number($row->area_terreno),
                'bedrooms' => $payload['features']['numBedRooms'],
                'bathrooms' => $payload['features']['numBathrooms'],
                'parking_spaces' => $payload['features']['numParking'],
                'age_years' => $payload['features']['antiquity'],
                'stratum' => min(6, (int) $payload['stratum']),
                'furnished' => $payload['features']['furnished'],
                'project_name' => $payload['features']['projectName'] ?: null,
                'in_closed_complex' => $payload['features']['project'],
                'status' => $this->localStatus($row->estado),
                'contact_name' => $payload['advisorName'],
                'contact_phone' => $payload['advisorPhone'],
                'contact_whatsapp' => $payload['advisorWhatsapp'],
                'contact_email' => $payload['advisorMail'] ?? null,
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

    protected function cityId(stdClass $row): int
    {
        if (Schema::connection('wordpress')->hasColumn('wp_jet_cct_ciudades', 'ciencuadras_city_id')) {
            $cityId = DB::connection('wordpress')
                ->table('wp_jet_cct_ciudades')
                ->whereRaw('LOWER(TRIM(ciudad)) = ?', [strtolower(trim((string) $row->ciudad))])
                ->value('ciencuadras_city_id');

            if ((int) $cityId > 0) {
                return (int) $cityId;
            }
        }

        return (int) config('portals.ciencuadras.default_city_id');
    }

    protected function localityId(stdClass $row): ?int
    {
        if (Schema::connection('wordpress')->hasColumn('wp_jet_cct_barrios', 'ciencuadras_locality_id')) {
            $query = DB::connection('wordpress')
                ->table('wp_jet_cct_barrios')
                ->where('cct_status', 'publish')
                ->whereRaw('LOWER(TRIM(barrio)) = ?', [strtolower(trim((string) $row->barrio))]);

            if ($row->ciudad) {
                $query->whereRaw('LOWER(TRIM(ciudad)) = ?', [strtolower(trim((string) $row->ciudad))]);
            }

            $localityId = $query->value('ciencuadras_locality_id');

            if ((int) $localityId > 0) {
                return (int) $localityId;
            }
        }

        $fallback = (int) config('portals.ciencuadras.default_locality_id');

        return $fallback > 0 ? $fallback : null;
    }

    protected function media(stdClass $row): array
    {
        $ids = collect([$row->foto_portada, ...explode(',', (string) $row->galeria)])
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->take(21)
            ->values();

        $images = $ids->isEmpty()
            ? collect()
            : DB::connection('wordpress')
                ->table('wp_posts')
                ->whereIn('ID', $ids)
                ->pluck('guid', 'ID');

        $media = $ids
            ->map(fn (int $id) => $images[$id] ?? null)
            ->filter()
            ->map(fn (string $url) => ['url' => $url, 'type' => 'I'])
            ->values();

        if ($row->video && $media->count() < 21) {
            $media->push(['url' => $row->video, 'type' => 'V']);
        }

        return $media->all();
    }

    protected function propertyTypeId(?string $type): int
    {
        $slug = Str::slug($type ?? '');
        return [
            'apartamento' => 10,
            'casa' => 11,
            'finca' => 12,
            'oficina' => 13,
            'consultorio' => 14,
            'bodega' => 15,
            'local' => 16,
            'lote' => 17,
            'edificio' => 21,
            'apartaestudio' => 29,
            'suite' => 36,
            'parqueadero' => 37,
            'casa-campestre' => 38,
            'deposito' => 39,
        ][$slug] ?? 10;
    }

    protected function transactionTypeId(?string $type): int
    {
        $slug = Str::slug($type ?? '');
        if (str_contains($slug, 'venta') && str_contains($slug, 'arriendo')) {
            return 3;
        }
        if (str_contains($slug, 'arriendo') || str_contains($slug, 'renta')) {
            return 2;
        }
        return 1;
    }

    protected function sellingPrice(stdClass $row, int $transactionId): int
    {
        return in_array($transactionId, [1, 3], true) ? (int) ($this->money($row->precio_venta) ?? 0) : 0;
    }

    protected function leasingFee(stdClass $row, int $transactionId): int
    {
        return in_array($transactionId, [2, 3], true) ? (int) ($this->money($row->precio_arriendo) ?? 0) : 0;
    }

    protected function description(stdClass $row): string
    {
        $text = $this->text(($row->descripcion ?? null) ?: $row->datos_adicionales ?: $row->punto_referencia);
        if (strlen($text) < 35) {
            $text = trim($this->propertyName($row) . '. ' . $this->text($row->direccion) . ' ' . $this->text($row->barrio));
        }

        return Str::limit($text, 1900, '');
    }

    protected function propertyName(stdClass $row): string
    {
        return Str::limit(trim(($row->tipo_inmueble ?: 'Inmueble') . ' en ' . ($row->tipo_negocio ?: 'gestión') . ($row->barrio ? ' - ' . $row->barrio : '')), 120, '');
    }

    protected function hasFeature(stdClass $row, array $needles): bool
    {
        $flat = Str::ascii(strtolower(implode(' ', [
            ...$this->decodeList($row->interiores),
            ...$this->decodeList($row->exteriores),
            ...$this->decodeList($row->alrededores),
            ...$this->decodeList($row->zonas_sociales),
        ])));

        foreach ($needles as $needle) {
            if (str_contains($flat, Str::ascii(strtolower($needle)))) {
                return true;
            }
        }

        return false;
    }

    protected function decodeList(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $value = @unserialize($raw, ['allowed_classes' => false]);
        if ($value === false && $raw !== 'b:0;') {
            $value = json_decode($raw, true);
        }

        if (is_array($value) && count($value) === 1 && is_string(reset($value))) {
            $nested = json_decode(reset($value), true);
            if (is_array($nested)) {
                return $nested;
            }
        }

        return is_array($value) ? $value : [$raw];
    }

    protected function parkingType(?string $type): int
    {
        $slug = Str::slug((string) $type);
        return str_contains($slug, 'cubierto') ? 2 : 1;
    }

    protected function localStatus(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'publico' => 'active',
            'arrendado' => 'rented',
            'vendido' => 'sold',
            'en borrador' => 'draft',
            'ocupado', 'no publicar' => 'paused',
            'en cierre', 'en proceso de cierre' => 'reserved',
            default => 'archived',
        };
    }

    protected function phone(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '57')) {
            return '+' . $digits;
        }
        return '+57' . $digits;
    }

    protected function text($value): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));
    }

    protected function money($value): ?float
    {
        $number = preg_replace('/[^\d.]/', '', (string) $value);
        return $number === '' ? null : (float) $number;
    }

    protected function number($value): ?float
    {
        $number = str_replace(',', '.', trim((string) $value));
        return is_numeric($number) ? (float) $number : null;
    }

    protected function integer($value): ?int
    {
        $number = preg_replace('/[^\d]/', '', (string) $value);
        return $number === '' ? null : (int) $number;
    }

    protected function yesNo($value): bool
    {
        $flat = strtolower(implode(' ', $this->decodeList((string) $value)));
        return str_contains($flat, 'si') || str_contains($flat, 'sí') || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }
}
