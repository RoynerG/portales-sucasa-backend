<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\WordPressPropertyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function __construct(protected WordPressPropertyRepository $wordpress) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->list($request->query())]);
        }

        $query = Property::query()
            ->with(['city', 'neighborhood', 'propertyType', 'transactionType', 'consultant', 'images', 'syncStatuses.integration']);

        $this->applyPropertyFilters($query, $request);

        $order = $request->query('orden', 'published_at');
        $direction = $request->query('dir', 'desc');
        $allowed = ['status', 'code', 'sale_price', 'rent_price', 'created_at', 'title', 'published_at'];
        if (! in_array($order, $allowed, true)) {
            $order = 'published_at';
        }
        $query->orderBy($order, $direction === 'asc' ? 'asc' : 'desc');

        $page = max(1, (int) $request->query('pagina', 1));
        $limit = min(100, max(1, (int) $request->query('limite', 25)));
        $properties = $query->forPage($page, $limit)->get();

        return response()->json(['Datos' => PropertyResource::collection($properties)]);
    }

    public function show(string $code): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            $property = $this->wordpress->findByCode($code);
            abort_unless($property, 404, 'Propiedad no encontrada.');

            return response()->json(['Datos' => [$property]]);
        }

        $property = Property::with([
            'city', 'neighborhood', 'propertyType', 'transactionType',
            'consultant', 'images', 'videos', 'floorPlans',
            'features', 'syncStatuses.integration',
        ])->where('code', $code)->firstOrFail();

        return response()->json(['Datos' => [new PropertyResource($property)]]);
    }

    public function statsByStatus(): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->statsByStatus()]);
        }

        $stats = Property::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'estado' => $row->status,
                'testado' => $row->total,
                'label' => $row->status,
            ]);

        return response()->json(['Datos' => $stats]);
    }

    public function portalSummary(Request $request): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->portalSummary($request->query())]);
        }

        $query = Property::query();
        $this->applyPropertyFilters($query, $request);

        $properties = $query
            ->with(['syncStatuses' => fn ($query) => $query->latest('updated_at')])
            ->get();

        return response()->json(['Datos' => [
            'total' => $properties->count(),
            'published' => $properties->filter(
                fn (Property $property) => $property->syncStatuses->contains('sync_status', 'synced')
            )->count(),
            'not_published' => $properties->filter(
                fn (Property $property) => ! $property->syncStatuses->contains('sync_status', 'synced')
            )->count(),
            'pending' => $properties->filter(
                fn (Property $property) => $property->syncStatuses->contains(
                    fn (PropertySyncStatus $status) => in_array($status->sync_status, ['pending', 'syncing'], true)
                )
            )->count(),
            'error' => $properties->filter(
                fn (Property $property) => $property->syncStatuses->contains('sync_status', 'error')
            )->count(),
        ]]);
    }

    public function distribution(): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->distribution()]);
        }

        $rows = Property::query()
            ->selectRaw('consultant_id, COUNT(*) as total')
            ->groupBy('consultant_id')
            ->with('consultant:id,name')
            ->get()
            ->map(fn ($row) => [
                'id_consultor' => $row->consultant_id,
                'consultor' => $row->consultant?->name ?? 'Sin asignar',
                'total' => $row->total,
            ]);

        return response()->json(['Datos' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateProperty($request);
        $property = Property::create(array_merge($data, [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        $this->attachFeatures($property, $request);
        $this->attachImages($property, $request);

        return response()->json(['Datos' => [new PropertyResource($property->fresh())]], 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $data = $this->validateProperty($request, partial: true);
        $property->update(array_merge($data, ['updated_by' => $request->user()->id]));

        if ($request->has('features')) {
            $this->attachFeatures($property, $request);
        }
        if ($request->has('images')) {
            $this->attachImages($property, $request);
        }

        return response()->json(['Datos' => [new PropertyResource($property->fresh())]]);
    }

    public function destroy(string $code): JsonResponse
    {
        Property::where('code', $code)->delete();

        return response()->json(['Datos' => 'OK']);
    }

    public function syncStatus(Request $request, string $code, int $integrationId): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'sync_status' => ['required', 'in:not_synced,pending,syncing,synced,error,paused'],
            'external_id' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string'],
            'last_error' => ['nullable', 'string'],
        ]);

        $status = PropertySyncStatus::updateOrCreate(
            ['property_id' => $property->id, 'integration_id' => $integrationId],
            array_merge($data, ['last_synced_at' => now()])
        );

        return response()->json(['Datos' => $status]);
    }

    private function validateProperty(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:32', 'unique:properties,code'],
            'title' => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'condition' => ['nullable', 'in:new,used,remodeled,under_construction'],
            'city_id' => [$req, 'integer', 'exists:cities,id'],
            'neighborhood_id' => ['nullable', 'integer', 'exists:neighborhoods,id'],
            'address' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'property_type_id' => [$req, 'integer', 'exists:property_types,id'],
            'transaction_type_id' => [$req, 'integer', 'exists:transaction_types,id'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'rent_price' => ['nullable', 'numeric', 'min:0'],
            'admin_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'area_total' => ['nullable', 'numeric', 'min:0'],
            'area_built' => ['nullable', 'numeric', 'min:0'],
            'area_private' => ['nullable', 'numeric', 'min:0'],
            'area_land' => ['nullable', 'numeric', 'min:0'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'bathrooms' => ['nullable', 'integer', 'min:0'],
            'half_bathrooms' => ['nullable', 'integer', 'min:0'],
            'parking_spaces' => ['nullable', 'integer', 'min:0'],
            'parking_type' => ['nullable', 'in:private,public,covered,uncovered'],
            'floor_number' => ['nullable', 'integer', 'min:0'],
            'age_years' => ['nullable', 'integer', 'min:0'],
            'year_built' => ['nullable', 'integer', 'min:1900'],
            'stratum' => ['nullable', 'integer', 'between:0,6'],
            'furnished' => ['boolean'],
            'project_name' => ['nullable', 'string'],
            'in_closed_complex' => ['boolean'],
            'status' => ['nullable', 'in:draft,active,paused,reserved,sold,rented,expired,archived'],
            'featured' => ['boolean'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'consultant_id' => ['nullable', 'integer', 'exists:consultants,id'],
        ]);
    }

    private function applyPropertyFilters($query, Request $request): void
    {
        if ($code = $request->query('codigo')) {
            $query->where(function ($q) use ($code) {
                $q->where('code', 'like', "%{$code}%")
                    ->orWhere('title', 'like', "%{$code}%")
                    ->orWhere('address', 'like', "%{$code}%");
            });
        }
        if ($cityId = $request->query('ciudad_id')) {
            $query->where('city_id', $cityId);
        }
        if ($typeId = $request->query('tipo_id')) {
            $query->where('property_type_id', $typeId);
        }
        if ($txId = $request->query('transaccion_id')) {
            $query->where('transaction_type_id', $txId);
        }
        if ($consultantId = $request->query('funcionario_id')) {
            $query->whereHas('consultant', fn ($q) => $q->where('legacy_id', $consultantId)->orWhere('id', $consultantId));
        }
        if ($status = $request->query('estado')) {
            $query->where('status', $status);
        }
        if ($min = $request->query('precio_min')) {
            $query->where(function ($q) use ($min) {
                $q->where('sale_price', '>=', $min)->orWhere('rent_price', '>=', $min);
            });
        }
        if ($max = $request->query('precio_max')) {
            $query->where(function ($q) use ($max) {
                $q->where('sale_price', '<=', $max)->orWhere('rent_price', '<=', $max);
            });
        }
        if ($bedrooms = $request->query('habitaciones')) {
            $query->where('bedrooms', '>=', (int) $bedrooms);
        }
    }

    private function attachFeatures(Property $property, Request $request): void
    {
        $features = collect($request->input('features', []))->mapWithKeys(function ($item) {
            $id = is_array($item) ? $item['id'] : $item;
            $value = is_array($item) ? ($item['value'] ?? null) : null;

            return [$id => ['value' => $value]];
        });
        $property->features()->sync($features);
    }

    private function attachImages(Property $property, Request $request): void
    {
        $images = $request->input('images', []);
        if (! is_array($images)) {
            return;
        }
        $property->images()->delete();
        foreach ($images as $i => $img) {
            $property->images()->create([
                'url' => $img['url'] ?? $img,
                'alt_text' => $img['alt'] ?? null,
                'is_cover' => $i === 0 || ($img['is_cover'] ?? false),
                'order' => $i,
            ]);
        }
    }
}
