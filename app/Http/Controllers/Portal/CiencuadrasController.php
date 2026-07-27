<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CiencuadrasController extends Controller
{
    public function __construct(
        protected CiencuadrasClient $cc,
        protected CiencuadrasPropertyMapper $mapper,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credential = $this->credential($request, forceRefresh: true);

        return response()->json(['Datos' => [
            'ok' => true,
            'environment' => config('portals.ciencuadras.environment'),
            'api_url' => config('portals.ciencuadras.api_url'),
            'expires_at' => $credential->access_token_expires_at?->toIso8601String(),
        ]]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'publish', status: 'A');
    }

    public function update(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'update', status: 'A');
    }

    public function pause(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'update', status: 'I');
    }

    public function delete(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'update', status: 'D');
    }

    public function consult(Request $request, string $code): JsonResponse
    {
        $cred = $this->credential($request);
        $integration = $this->integration();
        $environment = config('portals.ciencuadras.environment');
        $externalCode = $this->mapper->externalCode($code);
        $property = Property::where('code', $code)->first();
        $status = $property ? PropertySyncStatus::where([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => $environment,
        ])->first() : null;

        $lastResponse = $status?->last_response ?? [];
        $idRequest = $this->cc->extractIdRequest($lastResponse);
        $targetStatus = $lastResponse['target_status'] ?? null;
        $targetAction = $lastResponse['target_action'] ?? null;
        $statusResult = $idRequest
            ? $this->cc->consultStatus(['idRequest' => $idRequest], $cred)
            : null;
        $consultCode = $this->consultCodeForStatus($status, $externalCode);
        $propertyResult = $this->cc->consultProperty($consultCode, $cred);

        $response = [
            'previous' => $lastResponse,
            'target_action' => $targetAction,
            'target_status' => $targetStatus,
            'status_check' => $statusResult['data'] ?? null,
            'property_check' => $propertyResult['data'] ?? null,
        ];
        $syncStatus = $this->verifiedSyncState($statusResult, $propertyResult, $status?->sync_status, $targetStatus);
        $webUrl = $this->propertyWebUrl($code);

        if ($property) {
            $this->saveStatus(
                $property->id,
                $syncStatus,
                $externalCode,
                $response,
                $syncStatus === 'error' ? $this->errorMessage($response) : null,
                $webUrl
            );
        }

        return response()->json(['Datos' => [
            'ok' => $propertyResult['ok'] || ($statusResult['ok'] ?? false),
            'environment' => $environment,
            'external_code' => $consultCode,
            'id_request' => $idRequest,
            'action' => $targetAction,
            'target_status' => $targetStatus,
            'sync_status' => $syncStatus,
            'public_url' => $syncStatus === 'paused' ? null : ($this->extractPublicUrl($response) ?: $webUrl),
            'web_url' => $webUrl,
            'response' => $response,
        ]]);
    }

    public function payload(string $code): JsonResponse
    {
        $mapped = $this->mapper->fromCode($code);

        return response()->json(['Datos' => [
            'environment' => config('portals.ciencuadras.environment'),
            'source' => $mapped['source'],
            'errors' => $mapped['errors'],
            'payload' => $mapped['payload'],
        ]]);
    }

    protected function send(Request $request, string $code, string $action, string $status): JsonResponse
    {
        $mapped = $this->mapper->fromCode($code, $status);
        $property = $mapped['property'];
        $payloadPropertyCode = (string) $mapped['payload']['propertyCode'];
        if ($action !== 'publish') {
            $mapped['payload']['propertyCode'] = $this->payloadCodeForExistingListing($property->id, $payloadPropertyCode);
        }

        if ($mapped['errors']) {
            $this->saveStatus($property->id, 'error', $mapped['payload']['propertyCode'], [
                'validation_errors' => $mapped['errors'],
                'source' => $mapped['source'],
            ], implode(' ', $mapped['errors']));

            return response()->json(['Datos' => [
                'ok' => false,
                'environment' => config('portals.ciencuadras.environment'),
                'errors' => $mapped['errors'],
                'source' => $mapped['source'],
            ]], 422);
        }

        $cred = $this->credential($request);
        $result = $action === 'publish'
            ? $this->cc->insertProperty($mapped['payload'], $cred)
            : $this->cc->updateProperty($mapped['payload'], $cred);

        $idRequest = $result['ok'] ? $this->cc->extractIdRequest($result['data'] ?? []) : null;
        $statusResult = null;
        if ($idRequest) {
            $statusResult = $this->cc->consultStatus(['idRequest' => $idRequest], $cred);
        }
        $consultCode = $this->extractPropertyCode($statusResult['data'] ?? null)
            ?: $this->consultCodeFromPayload((string) $mapped['payload']['propertyCode']);
        $propertyResult = $result['ok']
            ? $this->cc->consultProperty($consultCode, $cred)
            : null;

        $response = [
            'target_action' => $action,
            'target_status' => $status,
            'request' => $result['data'],
            'status_check' => $statusResult['data'] ?? null,
            'property_check' => $propertyResult['data'] ?? null,
        ];
        $syncStatus = $this->syncState($result, $statusResult, $status, $propertyResult);
        $webUrl = $this->propertyWebUrl($code);
        $this->saveStatus(
            $property->id,
            $syncStatus,
            $consultCode,
            $response,
            $syncStatus === 'error' ? $this->errorMessage($response) : null,
            $webUrl
        );

        if ($syncStatus === 'paused') {
            $property->update(['status' => 'paused']);
        } elseif ($syncStatus === 'synced') {
            $property->update(['status' => 'active', 'published_at' => now()]);
        }

        return response()->json(['Datos' => [
            'ok' => $result['ok'] && $syncStatus !== 'error',
            'environment' => config('portals.ciencuadras.environment'),
            'external_code' => $consultCode,
            'action' => $action,
            'target_status' => $status,
            'sync_status' => $syncStatus,
            'id_request' => $idRequest,
            'public_url' => $syncStatus === 'paused' ? null : ($this->extractPublicUrl($response) ?: $webUrl),
            'web_url' => $webUrl,
            'response' => $response,
        ]]);
    }

    protected function credential(Request $request, bool $forceRefresh = false): PortalCredential
    {
        $username = config('portals.ciencuadras.username');
        $password = config('portals.ciencuadras.password');
        abort_if(! $username || ! $password, 422, 'Configura CIENCUADRAS_USERNAME y CIENCUADRAS_PASSWORD en .env.');

        $integration = $this->integration();
        $environment = config('portals.ciencuadras.environment');
        $credential = PortalCredential::where('user_id', $request->user()->id)
            ->where('integration_id', $this->integration()->id)
            ->first();

        if (! $forceRefresh && $credential && ! $credential->isExpired() && ($credential->data['environment'] ?? null) === $environment) {
            return $credential;
        }

        $result = $this->cc->login([
            'username' => $username,
            'password' => $password,
        ]);
        $token = $result['ok'] ? $this->cc->extractToken($result['data'] ?? []) : null;
        abort_if(! $token, 422, 'No fue posible iniciar sesión en Ciencuadras. Revisa credenciales y endpoint.');

        return PortalCredential::updateOrCreate(
            ['user_id' => $request->user()->id, 'integration_id' => $integration->id],
            [
                'access_token' => $token,
                'access_token_expires_at' => now()->addMinutes(55),
                'data' => [
                    'username' => $username,
                    'environment' => $environment,
                    'api_url' => config('portals.ciencuadras.api_url'),
                ],
            ]
        );
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'ciencuadras')->firstOrFail();
    }

    protected function saveStatus(int $propertyId, string $syncStatus, string $externalId, array $response, ?string $error, ?string $fallbackUrl = null): void
    {
        $status = PropertySyncStatus::firstOrNew([
            'property_id' => $propertyId,
            'integration_id' => $this->integration()->id,
            'environment' => config('portals.ciencuadras.environment'),
        ]);

        $status->fill([
            'sync_status' => $syncStatus,
            'external_id' => $externalId,
            'external_url' => $syncStatus === 'paused' ? null : ($this->extractPublicUrl($response) ?: $fallbackUrl ?: $status->external_url),
            'last_response' => $response,
            'last_error' => $error,
            'last_attempt_at' => now(),
            'last_synced_at' => in_array($syncStatus, ['synced', 'paused'], true) ? now() : $status->last_synced_at,
            'attempts' => ((int) $status->attempts) + 1,
        ]);
        $status->save();
    }

    protected function syncState(array $result, ?array $statusResult, string $targetStatus, ?array $propertyResult = null): string
    {
        if (! ($result['ok'] ?? false)) {
            return 'error';
        }

        $data = $statusResult['data'] ?? $result['data'] ?? [];
        $json = strtolower(json_encode($data));

        if ($targetStatus === 'I' || $targetStatus === 'D') {
            if ($this->responseIsPending($statusResult['data'] ?? null)) {
                return 'pending';
            }

            if (
                $this->responseHasSuccess($statusResult['data'] ?? null)
                || $this->responseHasNotFound($statusResult['data'] ?? null)
                || $this->responseHasNotFound($propertyResult['data'] ?? null)
            ) {
                return 'paused';
            }

            if (str_contains($json, 'error') || str_contains($json, 'fall')) {
                return 'error';
            }

            return 'pending';
        }

        if (str_contains($json, 'error') || str_contains($json, 'fall')) {
            return 'error';
        }

        if ($this->responseHasSuccess($propertyResult['data'] ?? null)) {
            return 'synced';
        }

        if (str_contains($json, 'pending')) {
            return 'pending';
        }

        return 'synced';
    }

    protected function verifiedSyncState(?array $statusResult, array $propertyResult, ?string $currentStatus, ?string $targetStatus = null): string
    {
        $statusData = $statusResult['data'] ?? null;
        $propertyData = $propertyResult['data'] ?? null;

        if ($targetStatus === 'I' || $targetStatus === 'D' || $currentStatus === 'paused') {
            if ($this->responseIsPending($statusData)) {
                return 'pending';
            }

            if (
                $this->responseHasSuccess($statusData)
                || $this->responseHasNotFound($statusData)
                || $this->responseHasNotFound($propertyData)
            ) {
                return 'paused';
            }

            if ($this->responseHasError($statusData) || ! ($propertyResult['ok'] ?? false)) {
                return 'error';
            }

            return 'pending';
        }

        if ($this->responseHasSuccess($statusData) || $this->responseHasSuccess($propertyData)) {
            return 'synced';
        }

        if ($this->responseIsPending($statusData)) {
            return 'pending';
        }

        if ($this->responseHasNotFound($propertyData) && $currentStatus === 'pending') {
            return 'pending';
        }

        if ($this->responseHasError($statusData) || $this->responseHasError($propertyData) || ! ($propertyResult['ok'] ?? false)) {
            return 'error';
        }

        return $currentStatus ?: 'pending';
    }

    protected function consultCodeForStatus(?PropertySyncStatus $status, string $default): string
    {
        return $this->extractPropertyCode($status?->last_response ?? null) ?: $default;
    }

    protected function payloadCodeForExistingListing(int $propertyId, string $default): string
    {
        $status = PropertySyncStatus::where([
            'property_id' => $propertyId,
            'integration_id' => $this->integration()->id,
            'environment' => config('portals.ciencuadras.environment'),
        ])->first();
        $existingCode = $this->extractPropertyCode($status?->last_response ?? null);
        $prefix = (string) config('portals.ciencuadras.property_code_prefix');

        if ($existingCode && $prefix && str_starts_with($existingCode, $prefix)) {
            return substr($existingCode, strlen($prefix));
        }

        return $default;
    }

    protected function consultCodeFromPayload(string $payloadCode): string
    {
        return (string) config('portals.ciencuadras.property_code_prefix') . $payloadCode;
    }

    protected function extractPropertyCode($data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (strtolower((string) $key) === 'propertycode' && is_scalar($value)) {
                return (string) $value;
            }

            if (is_array($value) && $found = $this->extractPropertyCode($value)) {
                return $found;
            }
        }

        return null;
    }

    protected function responseIsPending($data): bool
    {
        return str_contains(strtolower(json_encode($data ?? [])), 'pending');
    }

    protected function responseHasSuccess($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, '"status":"success"')
            || str_contains($json, 'statuscode":100')
            || str_contains($json, 'procesado')
            || str_contains($json, 'éxito')
            || str_contains($json, 'exito');
    }

    protected function responseHasError($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, '"status":"error"')
            || str_contains($json, 'error')
            || str_contains($json, 'fall');
    }

    protected function responseHasNotFound($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, 'no existe')
            || str_contains($json, 'not found')
            || str_contains($json, 'no tiene inmuebles');
    }

    protected function extractPublicUrl(array $response): ?string
    {
        $json = json_encode($response);
        if (! $json) {
            return null;
        }

        if (preg_match('/https?:\\\\?\\/\\\\?\\/[^"\\s]+ciencuadras\\.com[^"\\s]*/i', $json, $matches)) {
            return str_replace('\\/', '/', $matches[0]);
        }

        return null;
    }

    protected function propertyWebUrl(string $code): string
    {
        return 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-' . rawurlencode($code);
    }

    protected function errorMessage(array $response): string
    {
        return substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000);
    }
}
