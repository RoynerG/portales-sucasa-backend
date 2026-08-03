<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Integration;
use App\Models\Neighborhood;
use App\Models\PortalMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FincaraizNeighborhoodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,configured,pending'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $status = (string) ($data['status'] ?? 'all');
        $items = config('sources.properties') === 'wordpress'
            ? $this->wordpressItems()
            : $this->databaseItems();

        $summary = [
            'total' => $items->count(),
            'configured' => $items->where('configured', true)->count(),
            'pending' => $items->where('configured', false)->count(),
        ];

        if ($search !== '') {
            $needle = $this->normalize($search);
            $items = $items->filter(fn (array $item) => str_contains($this->normalize(implode(' ', [
                $item['neighborhood'],
                $item['city'],
                $item['department'],
                $item['fincaraiz_location_name'],
                $item['fincaraiz_location_id'],
            ])), $needle));
        }
        if ($status !== 'all') {
            $items = $items->where('configured', $status === 'configured');
        }

        return response()->json(['Datos' => [
            'environment' => config('portals.fincaraiz.environment', 'qa'),
            'summary' => $summary,
            'neighborhoods' => $items->values(),
        ]]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $location = $request->validate([
            'location_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'location_type' => ['required', 'in:NEIGHBOURHOOD'],
            'country' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        if (config('sources.properties') === 'wordpress') {
            $this->ensureWordpressColumns();
            $row = DB::connection('wordpress')->table('wp_jet_cct_barrios')->where('_ID', $id)->first();
            abort_unless($row, 404, 'Barrio no encontrado en WordPress.');
            DB::connection('wordpress')->table('wp_jet_cct_barrios')->where('_ID', $id)->update([
                'fincaraiz_location_id' => $location['location_id'],
                'fincaraiz_location_name' => $location['name'],
                'fincaraiz_location_type' => $location['location_type'],
            ]);
            $neighborhood = $this->ensureLocalNeighborhood(
                (string) $row->barrio,
                (string) $row->ciudad,
                (string) ($row->departamento ?: 'Colombia'),
                property_exists($row, 'codigo_postal') ? $row->codigo_postal : null
            );
        } else {
            $neighborhood = Neighborhood::with('city')->findOrFail($id);
        }

        PortalMapping::updateOrCreate(
            [
                'integration_id' => $this->integration()->id,
                'mappable_type' => Neighborhood::class,
                'mappable_id' => $neighborhood->id,
            ],
            [
                'external_id' => $location['location_id'],
                'external_name' => $location['name'],
                'extra' => [
                    'location_type' => $location['location_type'],
                    'country' => $location['country'] ?? null,
                    'state' => $location['state'] ?? null,
                    'city' => $location['city'] ?? null,
                    'environment' => config('portals.fincaraiz.environment', 'qa'),
                ],
            ]
        );

        return response()->json(['Datos' => [
            'ok' => true,
            'neighborhood_id' => $id,
            'local_neighborhood_id' => $neighborhood->id,
            'fincaraiz_location_id' => $location['location_id'],
        ]]);
    }

    protected function wordpressItems(): Collection
    {
        $this->ensureWordpressColumns();
        $integrationId = $this->integration()->id;
        $mapped = PortalMapping::query()
            ->where('integration_id', $integrationId)
            ->where('mappable_type', Neighborhood::class)
            ->with('mappable.city')
            ->get()
            ->filter(fn (PortalMapping $mapping) => $mapping->mappable instanceof Neighborhood)
            ->keyBy(fn (PortalMapping $mapping) => $this->locationKey(
                $mapping->mappable->name,
                $mapping->mappable->city?->name,
                $mapping->mappable->city?->department
            ));

        return DB::connection('wordpress')
            ->table('wp_jet_cct_barrios')
            ->where('cct_status', 'publish')
            ->orderBy('ciudad')
            ->orderBy('barrio')
            ->get()
            ->map(function ($row) use ($mapped): array {
                $mapping = $mapped->get($this->locationKey($row->barrio, $row->ciudad, $row->departamento));
                $locationId = trim((string) ($row->fincaraiz_location_id ?: $mapping?->external_id));

                return [
                    'id' => (int) $row->_ID,
                    'neighborhood' => $row->barrio,
                    'city' => $row->ciudad,
                    'department' => $row->departamento,
                    'fincaraiz_location_id' => $locationId ?: null,
                    'fincaraiz_location_name' => $row->fincaraiz_location_name ?: $mapping?->external_name,
                    'fincaraiz_location_type' => $row->fincaraiz_location_type ?: data_get($mapping?->extra, 'location_type'),
                    'configured' => Str::isUuid($locationId),
                ];
            });
    }

    protected function databaseItems(): Collection
    {
        $integrationId = $this->integration()->id;

        return Neighborhood::with(['city', 'portalMappings' => fn ($query) => $query->where('integration_id', $integrationId)])
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Neighborhood $neighborhood): array {
                $mapping = $neighborhood->portalMappings->first();
                $locationId = trim((string) $mapping?->external_id);

                return [
                    'id' => $neighborhood->id,
                    'neighborhood' => $neighborhood->name,
                    'city' => $neighborhood->city?->name,
                    'department' => $neighborhood->city?->department,
                    'fincaraiz_location_id' => $locationId ?: null,
                    'fincaraiz_location_name' => $mapping?->external_name,
                    'fincaraiz_location_type' => data_get($mapping?->extra, 'location_type'),
                    'configured' => Str::isUuid($locationId),
                ];
            });
    }

    protected function ensureLocalNeighborhood(string $name, string $cityName, string $department, ?string $postalCode): Neighborhood
    {
        $city = City::firstOrCreate(
            ['name' => trim($cityName), 'department' => trim($department)],
            [
                'dane_code' => '8'.substr((string) sprintf('%u', crc32($department.'|'.$cityName)), 0, 7),
                'country_code' => 'CO',
                'active' => true,
            ]
        );

        return Neighborhood::firstOrCreate(
            ['city_id' => $city->id, 'name' => trim($name)],
            ['postal_code' => $postalCode, 'active' => true]
        );
    }

    protected function ensureWordpressColumns(): void
    {
        $schema = Schema::connection('wordpress');
        abort_unless(
            $schema->hasColumn('wp_jet_cct_barrios', 'fincaraiz_location_id')
                && $schema->hasColumn('wp_jet_cct_barrios', 'fincaraiz_location_name')
                && $schema->hasColumn('wp_jet_cct_barrios', 'fincaraiz_location_type'),
            422,
            'Faltan las columnas de Fincaraíz en wp_jet_cct_barrios. Ejecuta php artisan migrate --force.'
        );
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'fincaraiz')->firstOrFail();
    }

    protected function locationKey(?string $neighborhood, ?string $city, ?string $department): string
    {
        return implode('|', array_map(fn ($value) => $this->normalize((string) $value), [
            $neighborhood,
            $city,
            $department,
        ]));
    }

    protected function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::ascii(Str::lower($value))));
    }
}
