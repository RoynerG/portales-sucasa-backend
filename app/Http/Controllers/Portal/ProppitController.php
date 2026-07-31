<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\ProppitClient;
use App\Services\Portals\ProppitPropertyMapper;
use Illuminate\Contracts\Encryption\DecryptException;
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
        $publisherId = trim((string) config('portals.proppit.publisher_external_id'));
        if ($publisherId === '') {
            return response()->json(['Datos' => [
                'ok' => false,
                'publisher_external_id' => $publisherId,
                'error' => [
                    'code' => 'missing_publisher_external_id',
                    'message' => 'Falta PROPPIT_PUBLISHER_EXTERNAL_ID.',
                    'resolution' => 'Configura el Publisher ID entregado o solicitado a Proppit y vuelve a probar la API.',
                ],
            ]], 422);
        }

        $publisher = $this->ensurePublisher($publisherId, $credential->access_token);
        if (! ($publisher['ok'] ?? false)) {
            return response()->json(['Datos' => [
                'ok' => false,
                'api_url' => config('portals.proppit.api_url'),
                'country' => config('portals.proppit.country'),
                'publisher_external_id' => $publisherId,
                'publisher' => $publisher,
                'error' => $publisher['error'] ?? null,
            ]], 422);
        }

        return response()->json(['Datos' => [
            'ok' => true,
            'api_url' => config('portals.proppit.api_url'),
            'country' => config('portals.proppit.country'),
            'publisher_external_id' => $publisherId,
            'publisher' => $publisher,
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

        $result = $this->decorateResult(
            $this->proppit->deleteAd($sync->external_id, $this->credential($request)->access_token)
        );
        $this->saveStatus($property, $result['ok'] ? 'paused' : 'error', $sync->external_id, $result, $result['ok'] ? null : $this->errorMessage($result));

        return response()->json(['Datos' => $this->responsePayload($result, $result['ok'] ? 'paused' : 'error', $sync->external_id)]);
    }

    public function verify(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync?->external_id, 422, 'El inmueble no está publicado en Proppit.');

        $result = $this->decorateResult(
            $this->proppit->getAd($sync->external_id, $this->credential($request)->access_token)
        );
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
        $publisherId = (string) config('portals.proppit.publisher_external_id');
        $result = $action === 'publish'
            ? $this->proppit->createAd($mapped['payload'], $token)
            : $this->proppit->updateAd($referenceId, $mapped['payload'], $token);

        if ($action === 'publish' && $this->isPublisherReferenceMissing($result) && $publisherId !== '') {
            $publisher = $this->ensurePublisher($publisherId, $token);
            if (($publisher['ok'] ?? false) && ($publisher['created'] ?? false)) {
                $result = $this->proppit->createAd($mapped['payload'], $token);
            }
        }

        $result = $this->decorateResult($result);
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
        abort_if(! $user || ! $password, 422, 'Configura PROPPIT_CLIENT_ID y PROPPIT_CLIENT_SECRET en .env.');
        $fingerprint = hash('sha256', $user."\0".$password);

        $credential = PortalCredential::where('user_id', $request->user()->id)
            ->where('integration_id', $this->integration()->id)
            ->first();

        $storedFingerprint = data_get($credential?->data, 'credential_fingerprint');
        $matchesCurrentCredentials = is_string($storedFingerprint)
            && hash_equals($storedFingerprint, $fingerprint);

        if (! $forceRefresh && $credential && ! $credential->isExpired() && $matchesCurrentCredentials) {
            try {
                if ($credential->access_token) {
                    return $credential;
                }
            } catch (DecryptException) {
                // La fila fue guardada en texto plano o con otra APP_KEY.
                // Se reemplaza después de obtener un token nuevo.
            }
        }

        $result = $this->proppit->token(['user' => $user, 'password' => $password]);
        $token = $result['data']['token'] ?? null;
        abort_if(! $result['ok'] || ! $token, 422, 'No fue posible iniciar sesión en Proppit. Revisa PROPPIT_CLIENT_ID y PROPPIT_CLIENT_SECRET.');

        $expiration = (int) ($result['data']['expiration'] ?? 0);

        if ($credential) {
            PortalCredential::query()->whereKey($credential->getKey())->delete();
        }

        return PortalCredential::create([
            'user_id' => $request->user()->id,
            'integration_id' => $this->integration()->id,
            'access_token' => $token,
            'access_token_expires_at' => $expiration > 0 ? now()->setTimestamp($expiration) : now()->addMinutes(55),
            'data' => [
                'user' => $user,
                'credential_fingerprint' => $fingerprint,
                'country' => config('portals.proppit.country'),
                'publisher_external_id' => config('portals.proppit.publisher_external_id'),
                'api_url' => config('portals.proppit.api_url'),
            ],
        ]);
    }

    protected function ensurePublisher(string $publisherId, string $token): array
    {
        $result = $this->proppit->getPublisher($publisherId, $token);
        if ($result['ok'] ?? false) {
            return [
                'ok' => true,
                'created' => false,
                'publishing_enabled' => (bool) data_get($result, 'data.publishingEnabled', false),
                'data' => $result['data'] ?? null,
            ];
        }

        if (! $this->isPublisherNotFound($result)) {
            return [
                'ok' => false,
                'created' => false,
                'error' => $this->decorateResult($result)['portal_error'] ?? null,
                'response' => $result['data'] ?? $result,
            ];
        }

        $created = $this->proppit->createPublisher($this->publisherPayload($publisherId), $token);
        if ($created['ok'] ?? false) {
            return [
                'ok' => true,
                'created' => true,
                'publishing_enabled' => (bool) data_get($created, 'data.publishingEnabled', false),
                'data' => $created['data'] ?? null,
                'message' => 'Publisher creado en Proppit. Queda pendiente de aprobación por soporte antes de salir visible en portales.',
            ];
        }

        return [
            'ok' => false,
            'created' => false,
            'error' => $this->decorateResult($created)['portal_error'] ?? null,
            'response' => $created['data'] ?? $created,
        ];
    }

    protected function publisherPayload(string $publisherId): array
    {
        return array_filter([
            'id' => $publisherId,
            'name' => config('portals.proppit.default_contact_name'),
            'email' => config('portals.proppit.default_contact_email'),
            'phone' => config('portals.proppit.default_contact_phone'),
        ], fn ($value) => $value !== null && $value !== '');
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
            'error' => $result['portal_error'] ?? null,
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
        $error = $response['portal_error'] ?? null;
        if (is_array($error)) {
            return implode(' ', array_filter([
                $error['message'] ?? null,
                $error['resolution'] ?? null,
                ! empty($error['request_id']) ? 'Request ID: '.$error['request_id'].'.' : null,
            ]));
        }

        return substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000);
    }

    protected function decorateResult(array $result): array
    {
        if ($result['ok'] ?? false) {
            return $result;
        }

        $body = data_get($result, 'data.body');
        $status = (int) (data_get($result, 'data.status') ?: (is_array($body) ? ($body['status'] ?? 0) : 0));
        $portalMessage = is_array($body)
            ? (string) ($body['error'] ?? '')
            : (string) data_get($result, 'data.error', '');
        $requestId = is_array($body) ? (string) ($body['requestId'] ?? '') : '';
        $normalized = strtolower($portalMessage);

        if ($this->isPublisherReferenceMissing($result)) {
            $message = 'Publisher no encontrado en Proppit.';
            $resolution = 'El Publisher ID configurado no existe para este Client ID/país o todavía no fue habilitado por Proppit. El backend intentará crearlo; si persiste, reporta el Request ID a soporte.';
            $code = $this->isPublisherNotFound($result) ? 'publisher_not_found' : 'publisher_reference_invalid';
        } elseif (str_contains($normalized, 'invalid credentials')) {
            $message = 'Client ID o Client Secret inválidos.';
            $resolution = 'Revisa PROPPIT_CLIENT_ID y PROPPIT_CLIENT_SECRET y vuelve a probar la API.';
            $code = 'invalid_credentials';
        } else {
            $message = $portalMessage !== '' ? $portalMessage : 'Proppit rechazó la solicitud.';
            $resolution = 'Revisa el detalle técnico y, si persiste, reporta el Request ID al soporte de Proppit.';
            $code = 'portal_error';
        }

        $result['portal_error'] = [
            'code' => $code,
            'message' => $message,
            'resolution' => $resolution,
            'status' => $status ?: null,
            'request_id' => $requestId ?: null,
            'publisher_external_id' => config('portals.proppit.publisher_external_id'),
        ];

        return $result;
    }

    protected function isPublisherNotFound(array $result): bool
    {
        $body = data_get($result, 'data.body');
        $portalMessage = is_array($body)
            ? (string) ($body['error'] ?? '')
            : (string) data_get($result, 'data.error', '');

        return str_contains(strtolower($portalMessage), 'publisher not found');
    }

    protected function isPublisherReferenceMissing(array $result): bool
    {
        if ($this->isPublisherNotFound($result)) {
            return true;
        }

        $body = data_get($result, 'data.body');
        $portalMessage = is_array($body)
            ? (string) ($body['error'] ?? '')
            : (string) data_get($result, 'data.error', '');
        $decoded = json_decode($portalMessage, true);

        if (is_array($decoded)) {
            $publisherErrors = data_get($decoded, 'data.publisherId', []);
            if (is_array($publisherErrors)) {
                foreach ($publisherErrors as $error) {
                    if (
                        is_array($error)
                        && strtolower((string) ($error['errorType'] ?? '')) === 'invalidreference'
                    ) {
                        return true;
                    }
                }
            }
        }

        $normalized = strtolower($portalMessage);

        return str_contains($normalized, 'publisherid')
            && str_contains($normalized, 'invalidreference');
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
