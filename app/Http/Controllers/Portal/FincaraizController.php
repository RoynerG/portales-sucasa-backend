<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\FincaraizClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FincaraizController extends Controller
{
    public function __construct(protected FincaraizClient $fr) {}

    public function clientInfo(Request $request): JsonResponse
    {
        $apiKey = config('portals.fincaraiz.api_key');
        abort_unless($apiKey, 400, 'No se ha configurado la API key de Fincaraíz.');
        return response()->json(['Datos' => $this->fr->getClientInfo($apiKey)]);
    }

    public function listings(Request $request): JsonResponse
    {
        $apiKey = $this->apiKey($request);
        $page = (int) $request->query('page', 1);
        $size = (int) $request->query('page_size', 20);
        return response()->json(['Datos' => $this->fr->listListings($apiKey, $page, $size)]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $apiKey = $this->apiKey($request);

        $payload = $this->fr->buildPayload($property);
        $result = $this->fr->createListing($payload, $apiKey);

        if ($result['ok'] && isset($result['data']['listing_id'])) {
            $property->syncStatuses()->updateOrCreate(
                ['integration_id' => $this->integration()->id],
                [
                    'sync_status' => 'synced',
                    'external_id' => $result['data']['listing_id'],
                    'last_response' => $result['data'],
                    'last_synced_at' => now(),
                    'last_attempt_at' => now(),
                ]
            );
            $property->update(['status' => 'active']);
        }

        return response()->json(['Datos' => $result]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync?->external_id, 400, 'No publicada en Fincaraíz.');

        $apiKey = $this->apiKey($request);
        $payload = $this->fr->buildPayload($property);
        $result = $this->fr->updateListing($sync->external_id, $payload, $apiKey);

        if ($result['ok']) {
            $sync->update([
                'sync_status' => 'synced',
                'last_response' => $result['data'] ?? null,
                'last_synced_at' => now(),
                'last_attempt_at' => now(),
            ]);
            $property->update(['status' => 'active']);
        }
        return response()->json(['Datos' => $result]);
    }

    public function pause(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync?->external_id, 400, 'No publicada en Fincaraíz.');

        $clientId = config('portals.fincaraiz.client_id', 'local-client');
        $apiKey = $this->apiKey($request);
        $result = $this->fr->changeStatus($sync->external_id, 'DELETED', $clientId, $apiKey);

        if ($result['ok']) {
            $sync->update(['sync_status' => 'paused', 'last_response' => $result['data'] ?? null]);
            $property->update(['status' => 'paused']);
        }
        return response()->json(['Datos' => $result]);
    }

    protected function apiKey(Request $request): string
    {
        $key = config('portals.fincaraiz.api_key');
        abort_unless($key, 400, 'No se ha configurado la API key de Fincaraíz.');
        return $key;
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'fincaraiz')->firstOrFail();
    }

    protected function syncStatus(Property $property): ?PropertySyncStatus
    {
        return $property->syncStatuses()
            ->where('integration_id', $this->integration()->id)
            ->first();
    }
}
