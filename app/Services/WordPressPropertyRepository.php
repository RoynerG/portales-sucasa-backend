<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class WordPressPropertyRepository
{
    protected const FUNCIONARIO_CARGO_IDS = [1, 6, 9, 10, 11, 12, 13, 14];

    public function enabled(): bool
    {
        return config('sources.properties') === 'wordpress';
    }

    public function list(array $filters): Collection
    {
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limite'] ?? 25)));

        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);
        $this->applyOrdering($query, $filters);

        $rows = $query->forPage($page, $limit)->get();
        $imageUrls = $this->attachmentUrls($rows);
        $syncStatuses = $this->syncStatuses($rows);

        return $rows->map(fn (stdClass $row) => $this->mapProperty($row, $imageUrls, syncStatuses: $syncStatuses));
    }

    public function findByCode(string $code): ?array
    {
        $row = $this->baseQuery()
            ->where('codigo', $code)
            ->first();

        if (! $row) {
            return null;
        }

        $rows = collect([$row]);

        return $this->mapProperty($row, $this->attachmentUrls($rows), withDetail: true, syncStatuses: $this->syncStatuses($rows));
    }

    public function statsByStatus(): Collection
    {
        return $this->baseQuery()
            ->selectRaw('COALESCE(NULLIF(estado, ""), "Sin estado") AS estado_original, COUNT(*) AS total')
            ->groupBy('estado_original')
            ->orderByDesc('total')
            ->get()
            ->map(fn (stdClass $row) => [
                'estado' => $this->mapStatus($row->estado_original),
                'testado' => (int) $row->total,
                'label' => $row->estado_original,
            ])
            ->groupBy('estado')
            ->map(fn (Collection $rows, string $estado) => [
                'estado' => $estado,
                'testado' => $rows->sum('testado'),
                'label' => $rows->pluck('label')->filter()->unique()->implode(', '),
                'labels' => $rows->pluck('label')->filter()->unique()->values()->all(),
            ])
            ->sortByDesc('testado')
            ->values();
    }

    public function portalSummary(array $filters = []): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);

        $codes = $query
            ->pluck('codigo')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        $latestStatuses = Property::query()
            ->whereIn('code', $codes)
            ->with(['syncStatuses' => fn ($query) => $query->with('integration')->latest('updated_at')])
            ->get()
            ->mapWithKeys(fn (Property $property) => [
                $property->code => $property->syncStatuses
                    ->filter(fn ($status) => $this->currentPortalEnvironment(
                        $status->integration?->slug,
                        $status->environment
                    ))
                    ->groupBy(fn ($status) => $status->integration_id.':'.($status->portal_variant ?: 'default'))
                    ->map(fn (Collection $rows) => $rows->first())
                    ->values(),
            ]);

        $published = 0;
        $pending = 0;
        $error = 0;

        foreach ($codes as $code) {
            $states = $latestStatuses->get($code, collect())->pluck('sync_status');
            $published += $states->contains('synced') ? 1 : 0;
            $pending += $states->contains(fn ($status) => in_array($status, ['pending', 'syncing'], true)) ? 1 : 0;
            $error += $states->contains('error') ? 1 : 0;
        }

        return [
            'total' => $codes->count(),
            'published' => $published,
            'not_published' => max(0, $codes->count() - $published),
            'pending' => $pending,
            'error' => $error,
        ];
    }

    public function distribution(): Collection
    {
        return $this->baseQuery()
            ->selectRaw('COALESCE(NULLIF(id_funcionario, ""), "sin-asignar") AS id_consultor, COALESCE(NULLIF(funcionario, ""), "Sin asignar") AS consultor, COUNT(*) AS total')
            ->groupBy('id_consultor', 'consultor')
            ->orderByDesc('total')
            ->get()
            ->map(fn (stdClass $row) => [
                'id_consultor' => $row->id_consultor,
                'consultor' => $row->consultor,
                'total' => (int) $row->total,
            ]);
    }

    public function consultants(): Collection
    {
        return DB::connection('wordpress')
            ->table('wp_jet_cct_funcionarios')
            ->where('cct_status', 'publish')
            ->whereIn('id_cargo', self::FUNCIONARIO_CARGO_IDS)
            ->orderBy('nombre')
            ->get()
            ->map(fn (stdClass $row) => [
                'id' => (int) $row->_ID,
                'legacy_id' => $row->id_empleado,
                'cargo_id' => isset($row->id_cargo) ? (int) $row->id_cargo : null,
                'name' => $row->nombre ?: 'Sin nombre',
                'email' => $row->correo,
                'phone' => $row->celular,
                'whatsapp' => $row->celular,
                'department' => $row->gestion ?: $row->rol,
                'position' => $row->rol,
                'active' => $this->yesNo($row->activo) || $row->activo === '' || $row->activo === null,
                'properties_count' => $this->propertiesCountByFuncionario($row->id_empleado),
            ]);
    }

    public function neighborhoods(?string $cityId = null, ?string $search = null): Collection
    {
        $query = DB::connection('wordpress')
            ->table('wp_jet_cct_barrios')
            ->where('cct_status', 'publish');

        if ($search) {
            $query->where('barrio', 'like', "%{$search}%");
        }

        return $query->orderBy('barrio')->get()->map(fn (stdClass $row) => [
            'id' => (int) $row->_ID,
            'name' => $row->barrio ?: 'Sin barrio',
            'zone' => $row->ruta_asignada,
            'postal_code' => $row->codigo_postal,
            'lat' => $this->number($row->latitud),
            'lng' => $this->number($row->longitud),
            'active' => true,
            'city' => [
                'name' => $row->ciudad,
                'department' => $row->departamento,
                'country_code' => $row->pais ?: 'CO',
            ],
        ]);
    }

    public function cities(): Collection
    {
        return DB::connection('wordpress')
            ->table('wp_jet_cct_ciudades')
            ->where('cct_status', 'publish')
            ->orderBy('ciudad')
            ->get()
            ->map(fn (stdClass $row) => [
                'id' => (int) $row->_ID,
                'name' => $row->ciudad,
                'department' => $row->departamento,
                'country_code' => $row->pais ?: 'CO',
                'active' => true,
            ]);
    }

    public function propertyTypes(): Collection
    {
        return $this->baseQuery()
            ->selectRaw('DISTINCT tipo_inmueble')
            ->whereNotNull('tipo_inmueble')
            ->where('tipo_inmueble', '<>', '')
            ->orderBy('tipo_inmueble')
            ->get()
            ->values()
            ->map(fn (stdClass $row, int $index) => [
                'id' => $index + 1,
                'slug' => str($row->tipo_inmueble)->slug()->toString(),
                'name' => $row->tipo_inmueble,
                'active' => true,
                'order' => $index + 1,
            ]);
    }

    public function transactionTypes(): Collection
    {
        return $this->baseQuery()
            ->selectRaw('DISTINCT tipo_negocio')
            ->whereNotNull('tipo_negocio')
            ->where('tipo_negocio', '<>', '')
            ->orderBy('tipo_negocio')
            ->get()
            ->values()
            ->map(fn (stdClass $row, int $index) => [
                'id' => $index + 1,
                'slug' => str($row->tipo_negocio)->slug()->toString(),
                'name' => $row->tipo_negocio,
                'active' => true,
                'order' => $index + 1,
            ]);
    }

    public function destinations(): Collection
    {
        return $this->baseQuery()
            ->selectRaw('DISTINCT destinacion')
            ->whereNotNull('destinacion')
            ->where('destinacion', '<>', '')
            ->orderBy('destinacion')
            ->get()
            ->values()
            ->map(fn (stdClass $row, int $index) => [
                'id' => $index + 1,
                'slug' => str($row->destinacion)->slug()->toString(),
                'name' => $row->destinacion,
                'active' => true,
                'order' => $index + 1,
            ]);
    }

    public function propertiesCountByFuncionario($legacyId): int
    {
        return $this->baseQuery()
            ->where('id_funcionario', (string) $legacyId)
            ->count();
    }

    public function features(?string $group = null): Collection
    {
        $tables = [
            'internal' => ['table' => 'wp_jet_cct_caract_internas', 'field' => 'valor'],
            'external' => ['table' => 'wp_jet_cct_caract_externas', 'field' => 'valor'],
            'surrounding' => ['table' => 'wp_jet_cct_alrededores', 'field' => 'valor'],
        ];

        return collect($tables)
            ->when($group, fn (Collection $items) => $items->only($group))
            ->flatMap(function (array $meta, string $key) {
                return DB::connection('wordpress')
                    ->table($meta['table'])
                    ->where('cct_status', 'publish')
                    ->orderBy($meta['field'])
                    ->get()
                    ->map(fn (stdClass $row) => [
                        'id' => (int) $row->_ID,
                        'group' => $key,
                        'slug' => str($row->{$meta['field']} ?? $row->_ID)->slug()->toString(),
                        'name' => $row->{$meta['field']} ?? '',
                        'active' => true,
                    ]);
            })
            ->values();
    }

    protected function baseQuery(): Builder
    {
        return DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish');
    }

    protected function currentPortalEnvironment(?string $portal, ?string $environment): bool
    {
        if (! $portal || ! $environment) {
            return true;
        }

        $expected = match ($portal) {
            'ciencuadras' => config('portals.ciencuadras.environment', 'production'),
            'mercadolibre' => config('portals.mercadolibre.environment', 'production'),
            default => 'production',
        };

        return $environment === $expected;
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if ($code = $filters['codigo'] ?? null) {
            $query->where(function (Builder $q) use ($code) {
                $q->where('codigo', 'like', "%{$code}%")
                    ->orWhere('barrio', 'like', "%{$code}%")
                    ->orWhere('direccion', 'like', "%{$code}%")
                    ->orWhere('funcionario', 'like', "%{$code}%")
                    ->orWhere('tipo_inmueble', 'like', "%{$code}%")
                    ->orWhere('tipo_negocio', 'like', "%{$code}%");
            });
        }

        if ($status = $filters['estado'] ?? null) {
            $legacyStatuses = collect([
                'draft' => ['En borrador'],
                'active' => ['Publico'],
                'paused' => ['Ocupado', 'No publicar'],
                'reserved' => ['En cierre', 'En proceso de cierre'],
                'sold' => ['Vendido'],
                'rented' => ['Arrendado'],
                'archived' => ['Desistido', 'Vetado'],
            ])->get($status, [$status]);

            $query->whereIn('estado', $legacyStatuses);
        }

        if ($type = $filters['tipo_inmueble'] ?? null) {
            $query->where('tipo_inmueble', $type);
        }

        if ($transaction = $filters['tipo_negocio'] ?? null) {
            $query->where('tipo_negocio', $transaction);
        }

        if ($destination = $filters['destinacion'] ?? null) {
            $query->where('destinacion', $destination);
        }

        if ($consultantId = $filters['funcionario_id'] ?? null) {
            $query->where('id_funcionario', (string) $consultantId);
        }

        if ($city = $filters['ciudad'] ?? null) {
            $query->where('ciudad', $city);
        }

        if ($neighborhood = $filters['barrio'] ?? null) {
            $query->where('barrio', $neighborhood);
        }
    }

    protected function applyOrdering(Builder $query, array $filters): void
    {
        $order = $filters['orden'] ?? 'published_at';
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $column = [
            'status' => 'estado',
            'code' => 'codigo',
            'sale_price' => 'precio_venta',
            'rent_price' => 'precio_arriendo',
            'created_at' => 'cct_created',
            'title' => 'codigo',
            'published_at' => 'fecha_publicacion',
        ][$order] ?? 'fecha_publicacion';

        $query->orderBy($column, $direction);
    }

    protected function mapProperty(stdClass $row, array $imageUrls, bool $withDetail = false, array $syncStatuses = []): array
    {
        $images = $this->imageIds($row)
            ->map(fn (int $id, int $index) => [
                'id' => $id,
                'url' => $imageUrls[$id] ?? null,
                'thumbnail' => $imageUrls[$id] ?? null,
                'is_cover' => $index === 0,
                'order' => $index,
            ])
            ->filter(fn (array $image) => $image['url'])
            ->values();

        $features = $withDetail ? collect([
            ...$this->featureValues($row->interiores, 'internal'),
            ...$this->featureValues($row->exteriores, 'external'),
            ...$this->featureValues($row->alrededores, 'surrounding'),
            ...$this->featureValues($row->zonas_sociales, 'external'),
        ])->values() : collect();

        $displayPrice = $this->money($row->precio_arriendo) ?: $this->money($row->precio_venta);

        return [
            'id' => (int) $row->_ID,
            'legacy_id' => (int) $row->_ID,
            'code' => (string) $row->codigo,
            'title' => trim(($row->tipo_inmueble ?: 'Inmueble').' en '.($row->tipo_negocio ?: 'gestión').($row->barrio ? ' - '.$row->barrio : '')),
            'description' => ($row->descripcion ?? null) ?: $row->datos_adicionales ?: $row->punto_referencia,
            'condition' => 'used',
            'city' => $row->ciudad,
            'neighborhood' => $row->barrio,
            'address' => $row->direccion,
            'address_extra' => $row->direccion_fisica,
            'lat' => $this->number($row->latitud),
            'lng' => $this->number($row->longitud),
            'property_type' => $row->tipo_inmueble,
            'transaction_type' => $row->tipo_negocio,
            'sale_price' => $this->money($row->precio_venta),
            'rent_price' => $this->money($row->precio_arriendo),
            'admin_price' => $this->money($row->precio_admin),
            'currency' => 'COP',
            'display_price' => $displayPrice,
            'area_total' => $this->number($row->area_construida ?: $row->area_terreno),
            'area_built' => $this->number($row->area_construida),
            'area_private' => $this->number($row->area_privada),
            'area_land' => $this->number($row->area_terreno),
            'display_area' => $this->number($row->area_construida ?: $row->area_terreno),
            'bedrooms' => $this->integer($row->habitaciones),
            'bathrooms' => $this->integer($row->banos),
            'parking_spaces' => $this->integer($row->parqueaderos),
            'age_years' => $this->integer($row->edad),
            'stratum' => $this->integer($row->estrato),
            'furnished' => $this->yesNo($row->amoblado),
            'status' => $this->mapStatus($row->estado),
            'legacy_status' => $row->estado,
            'featured' => $this->yesNo($row->destacado) || $this->yesNo($row->marcado_destacado),
            'published_at' => $this->timestamp($row->fecha_publicacion),
            'consultant' => $row->funcionario,
            'consultant_id' => $row->id_funcionario,
            'contact_name' => $row->funcionario,
            'images' => $images,
            'features' => $features,
            'sync_statuses' => $syncStatuses[(string) $row->codigo] ?? [],
            'created_at' => $row->cct_created,
            'updated_at' => $row->cct_modified,
        ];
    }

    protected function attachmentUrls(Collection $rows): array
    {
        $ids = $rows
            ->flatMap(fn (stdClass $row) => $this->imageIds($row))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::connection('wordpress')
            ->table('wp_posts')
            ->whereIn('ID', $ids)
            ->pluck('guid', 'ID')
            ->all();
    }

    protected function syncStatuses(Collection $rows): array
    {
        $codes = $rows
            ->pluck('codigo')
            ->map(fn ($code) => (string) $code)
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return [];
        }

        return Property::query()
            ->whereIn('code', $codes)
            ->with('syncStatuses.integration')
            ->get()
            ->mapWithKeys(fn (Property $property) => [
                $property->code => $property->syncStatuses->map(fn ($status) => [
                    'portal' => $status->integration?->slug,
                    'portal_name' => $status->integration?->name,
                    'environment' => $status->environment,
                    'portal_variant' => $status->portal_variant,
                    'sync_status' => $status->sync_status,
                    'external_id' => $status->external_id,
                    'external_url' => $status->external_url,
                    'last_response' => $status->last_response,
                    'last_error' => $status->last_error,
                    'last_synced_at' => $status->last_synced_at?->toIso8601String(),
                ])->values()->all(),
            ])
            ->all();
    }

    protected function imageIds(stdClass $row): Collection
    {
        return collect([
            $row->foto_portada,
            ...explode(',', (string) $row->galeria),
        ])
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->values();
    }

    protected function featureValues(?string $raw, string $group): array
    {
        return collect($this->decodeList($raw))
            ->flatten()
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->map(fn ($name) => [
                'id' => abs(crc32($group.':'.$name)),
                'name' => $name,
                'group' => $group,
                'icon' => null,
                'value' => null,
            ])
            ->values()
            ->all();
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

    protected function mapStatus(?string $status): string
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

    protected function money($value): ?float
    {
        $number = $this->normalizeNumericText($value);

        return $number === '' ? null : (float) $number;
    }

    protected function number($value): ?float
    {
        $number = $this->normalizeNumericText($value, keepThousands: false);

        return is_numeric($number) ? (float) $number : null;
    }

    protected function normalizeNumericText($value, bool $keepThousands = true): string
    {
        $number = trim((string) $value);
        if ($number === '') {
            return '';
        }

        $number = preg_replace('/[^\d,.-]/', '', $number);
        if ($number === '') {
            return '';
        }

        if (
            str_contains($number, ',')
            && ! str_contains($number, '.')
            && (substr_count($number, ',') > 1 || preg_match('/^\d{1,3}(,\d{3})+$/', $number))
        ) {
            $number = str_replace(',', '', $number);
        } elseif (str_contains($number, ',')) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } elseif ($keepThousands && (substr_count($number, '.') > 1 || preg_match('/^\d{1,3}(\.\d{3})+$/', $number))) {
            $number = str_replace('.', '', $number);
        }

        return preg_replace('/[^\d.-]/', '', $number);
    }

    protected function integer($value): ?int
    {
        $number = preg_replace('/[^\d]/', '', (string) $value);

        return $number === '' ? null : (int) $number;
    }

    protected function timestamp($value): ?string
    {
        return is_numeric($value) && (int) $value > 0
            ? date(DATE_ATOM, (int) $value)
            : null;
    }

    protected function yesNo($value): bool
    {
        $flat = strtolower(implode(' ', $this->decodeList((string) $value)));

        return str_contains($flat, 'si') || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'si', 'sí'], true);
    }
}
