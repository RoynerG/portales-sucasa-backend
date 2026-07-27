<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\ProppitClient;
use App\Services\Portals\ProppitPropertyMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProppitController extends Controller
{
    public function __construct(
        protected ProppitClient $proppit,
        protected ProppitPropertyMapper $mapper,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credential = $this->credential($request, true);

        return response()->json(['Datos' => [
            'ok' => true,
            'api_url' => config('portals.proppit.api_url'),
            'country' => config('portals.proppit.country'),
            'publisher_external_id' => config('portals.proppit.publisher_external_id'),
            'expires_at' => $credential->access_token_expires_at?->toIso8601String(),
        ]]);
    }

    public function payload(string $code): JsonResponse
    {
        $mapped = $this->mapper->fromCode($code);

        return response()->json(['Datos' => [
            'source' => $mapped['source'],
            'errors' => $mapped['errors'],
            'payload' => $mapped['payload'],
        ]]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        $mapped = $this->mapper->fromCode($code);

        return $this->send($request, $mapped, 'publish');
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $mapped = $this->mapper->fromCode($code);

        return $this->send($request, $mapped, 'update');
    }

    public function pause(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync?->external_id, 422, 'El inmueble no está publicado en Proppit.');

        $result = $this->proppit->deleteAd($sync->external_id, $this->credential($request)->access_token);
        $this->saveStatus($property, $result['ok'] ? 'paused' : 'error', $sync->external_id, $result, $result['ok'] ? null : $this->errorMessage($result));

        return response()->json(['Datos' => $this->responsePayload($result, $result['ok'] ? 'paused' : 'error', $sync->external_id)]);
    }

    public function verify(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync?->external_id, 422, 'El inmueble no está publicado en Proppit.');

        $result = $this->proppit->getAd($sync->external_id, $this->credential($request)->access_token);
        $this->saveStatus($property, $result['ok'] ? 'synced' : 'error', $sync->external_id, $result, $result['ok'] ? null : $this->errorMessage($result));

        return response()->json(['Datos' => $this->responsePayload($result, $result['ok'] ? 'synced' : 'error', $sync->external_id)]);
    }

    protected function send(Request $request, array $mapped, string $action): JsonResponse
    {
        /** @var Property $property */
        $property = $mapped['property'];
        $referenceId = $mapped['payload']['referenceId'];

        if ($mapped['errors']) {
            $this->saveStatus($property, 'error', $referenceId, [
                'validation_errors' => $mapped['errors'],
                'source' => $mapped['source'],
            ], implode(' ', $mapped['errors']));

            return response()->json(['Datos' => [
                'ok' => false,
                'errors' => $mapped['errors'],
                'source' => $mapped['source'],
            ]], 422);
        }

        $token = $this->credential($request)->access_token;
        $result = $action === 'publish'
            ? $this->proppit->createAd($mapped['payload'], $token)
            : $this->proppit->updateAd($referenceId, $mapped['payload'], $token);
        $status = $result['ok'] ? 'synced' : 'error';

        $this->saveStatus($property, $status, $referenceId, $result, $status === 'error' ? $this->errorMessage($result) : null);

        if ($status === 'synced') {
            $property->update(['status' => 'active', 'published_at' => now()]);
        }

        return response()->json(['Datos' => $this->responsePayload($result, $status, $referenceId)]);
    }

    protected function credential(Request $request, bool $forceRefresh = false): PortalCredential
    {
        $user = config('portals.proppit.user');
        $password = config('portals.proppit.password');
        abort_if(! $user || ! $password, 422, 'Configura PROPPIT_USER y PROPPIT_PASSWORD en .env.');

        $credential = PortalCredential::where('user_id', $request->user()->id)
            ->where('integration_id', $this->integration()->id)
            ->first();

        if (! $forceRefresh && $credential && ! $credential->isExpired()) {
            return $credential;
        }

        $result = $this->proppit->token(['user' => $user, 'password' => $password]);
        $token = $result['data']['token'] ?? null;
        abort_if(! $result['ok'] || ! $token, 422, 'No fue posible iniciar sesión en Proppit. Revisa PROPPIT_USER y PROPPIT_PASSWORD.');

        $expiration = (int) ($result['data']['expiration'] ?? 0);

        return PortalCredential::updateOrCreate(
            ['user_id' => $request->user()->id, 'integration_id' => $this->integration()->id],
            [
                'access_token' => $token,
                'access_token_expires_at' => $expiration > 0 ? now()->setTimestamp($expiration) : now()->addMinutes(55),
                'data' => [
                    'user' => $user,
                    'country' => config('portals.proppit.country'),
                    'publisher_external_id' => config('portals.proppit.publisher_external_id'),
                    'api_url' => config('portals.proppit.api_url'),
                ],
            ]
        );
    }

    protected function saveStatus(Property $property, string $syncStatus, string $externalId, array $response, ?string $error): void
    {
        PropertySyncStatus::updateOrCreate(
            [
                'property_id' => $property->id,
                'integration_id' => $this->integration()->id,
                'environment' => 'production',
            ],
            [
                'sync_status' => $syncStatus,
                'external_id' => $externalId,
                'external_url' => $this->externalUrl($externalId),
                'last_response' => $response,
                'last_error' => $error,
                'last_attempt_at' => now(),
                'last_synced_at' => in_array($syncStatus, ['synced', 'paused'], true) ? now() : null,
                'attempts' => 1,
            ]
        );
    }

    protected function responsePayload(array $result, string $syncStatus, string $externalId): array
    {
        return [
            'ok' => $result['ok'],
            'external_code' => $externalId,
            'sync_status' => $syncStatus,
            'public_url' => $this->externalUrl($externalId),
            'response' => $result['data'] ?? $result,
        ];
    }

    protected function externalUrl(string $externalId): ?string
    {
        $base = config('portals.proppit.public_url');

        return $base ? rtrim($base, '/') . '/' . rawurlencode($externalId) : null;
    }

    protected function errorMessage(array $response): string
    {
        return substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000);
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'proppit')->firstOrFail();
    }

    protected function syncStatus(Property $property): ?PropertySyncStatus
    {
        return $property->syncStatuses()
            ->where('integration_id', $this->integration()->id)
            ->first();
    }
}
