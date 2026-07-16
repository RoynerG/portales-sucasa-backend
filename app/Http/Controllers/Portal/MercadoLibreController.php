<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\MercadoLibreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MercadoLibreController extends Controller
{
    public function __construct(protected MercadoLibreClient $ml) {}

    public function redirect(Request $request)
    {
        $clientId = config('portals.mercadolibre.client_id');
        abort_unless($clientId, 400, 'No se ha configurado el client_id de MercadoLibre para este usuario.');

        $url = $this->ml->authorizeUrl($request->user()->id, $clientId);
        return response()->json(['Datos' => ['authorize_url' => $url]]);
    }

    public function callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        abort_unless($code && $state, 400, 'Parámetros OAuth inválidos.');

        $credential = $this->ml->exchangeCode($code, $state);

        return redirect()->away(
            config('frontend.frontend_url') . '/#/integrations?ml=connected'
        );
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $credential = $this->resolveCredential($request->user()->id);

        $payload = $this->ml->buildPayload($property);
        $result = $this->ml->createItem($payload, $credential);

        if ($result['ok'] && isset($result['data']['id'])) {
            $property->syncStatuses()->updateOrCreate(
                ['integration_id' => $this->integration()->id],
                [
                    'sync_status' => 'synced',
                    'external_id' => $result['data']['id'],
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
        abort_unless($sync?->external_id, 400, 'La propiedad no está publicada en MercadoLibre.');

        $credential = $this->resolveCredential($request->user()->id);
        $payload = $this->ml->buildPayload($property);
        $result = $this->ml->updateItem($sync->external_id, $payload, $credential);

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
        abort_unless($sync?->external_id, 400, 'La propiedad no está publicada en MercadoLibre.');

        $credential = $this->resolveCredential($request->user()->id);
        $result = $this->ml->changeStatus($sync->external_id, 'paused', $credential);

        if ($result['ok']) {
            $sync->update(['sync_status' => 'paused', 'last_response' => $result['data'] ?? null]);
            $property->update(['status' => 'paused']);
        }

        return response()->json(['Datos' => $result]);
    }

    public function verify(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync?->external_id, 400, 'La propiedad no está publicada en MercadoLibre.');

        $credential = $this->resolveCredential($request->user()->id);
        $result = $this->ml->getItem($sync->external_id, $credential);

        if ($result['ok']) {
            $sync->update(['last_response' => $result['data'] ?? null, 'last_attempt_at' => now()]);
        }

        return response()->json(['Datos' => $result]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $topic = $request->input('topic') ?? $request->input('_topic');
        $resource = $request->input('resource') ?? $request->input('id');

        \Log::info('MercadoLibre webhook received', compact('topic', 'resource'));

        return response()->json(['Datos' => 'OK']);
    }

    protected function resolveCredential(int $userId): PortalCredential
    {
        $cred = PortalCredential::where('user_id', $userId)
            ->where('integration_id', $this->integration()->id)
            ->firstOrFail();
        abort_if($cred->isExpired(), 401, 'El token de MercadoLibre ha expirado. Vuelve a conectar.');
        return $cred;
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'mercadolibre')->firstOrFail();
    }

    protected function syncStatus(Property $property): ?PropertySyncStatus
    {
        return $property->syncStatuses()
            ->where('integration_id', $this->integration()->id)
            ->first();
    }
}
