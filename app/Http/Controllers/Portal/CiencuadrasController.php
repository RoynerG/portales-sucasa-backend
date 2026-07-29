<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CiencuadrasController extends Controller
{
    public function __construct(
        protected CiencuadrasClient $cc,
        protected CiencuadrasPropertyMapper $mapper,
        protected CiencuadrasActiveProperties $activeProperties,
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
        return $this->send($request, $code, action: 'pause', status: 'I');
    }

    public function delete(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'delete', status: 'D');
    }

    public function consult(Request $request, string $code): JsonResponse
    {
        $cred = $this->credential($request);
        $integration = $this->integration();
        $environment = config('portals.ciencuadras.environment');
        $externalCode = $this->mapper->lookupCode($code);
        $property = Property::where('code', $code)->first();
        $status = $property ? PropertySyncStatus::where([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => $environment,
        ])->first() : null;

        $lastResponse = $status?->last_response ?? [];
        $idRequest = $this->cc->extractIdRequest($lastResponse);
        $targetStatus = $this->responseValue($lastResponse, 'target_status');
        $targetAction = $this->responseValue($lastResponse, 'target_action');
        $statusResult = $idRequest
            ? $this->cc->consultStatus(['idRequest' => $idRequest], $cred)
            : null;
        $consultCode = $this->consultCodeForStatus($status, $externalCode);
        $propertyResult = $this->cc->consultProperty($consultCode, $cred);

        $response = $this->verificationResponse($idRequest, $targetAction, $targetStatus, $statusResult['data'] ?? null, $propertyResult['data'] ?? null);
        $syncStatus = $this->verifiedSyncState($statusResult, $propertyResult, $status?->sync_status, $targetStatus);
        $webUrl = $this->propertyWebUrl($code);

        if ($property) {
            $this->saveStatus(
                $property->id,
                $syncStatus,
                $externalCode,
                $response,
                $syncStatus === 'error' ? $this->errorMessage($response) : null
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
            'public_url' => $this->publicUrlForStatus($syncStatus, $targetStatus, $response),
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

    public function bulkCandidates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:update,pause'],
            'fresh' => ['nullable', 'boolean'],
        ]);

        $codes = $this->activeProperties->sourceCodes(
            $request->boolean('fresh', true)
        );
        abort_if($codes === null, 503, 'No fue posible consultar el inventario de Ciencuadras.');

        $existingCodes = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->whereIn('codigo', $codes)
            ->pluck('codigo')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        return response()->json(['Datos' => [
            'portal' => 'ciencuadras',
            'action' => $data['action'],
            'environment' => config('portals.ciencuadras.environment'),
            'total' => $existingCodes->count(),
            'codes' => $existingCodes,
        ]]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:publish,update,pause'],
            'codes' => ['required', 'array', 'min:1', 'max:20'],
            'codes.*' => ['required', 'string', 'distinct', 'max:32'],
        ]);

        $action = $data['action'];
        $targetStatus = $action === 'pause' ? 'I' : 'A';
        $credential = $this->credential($request);
        $inspectedCodes = in_array($action, ['update', 'pause'], true)
            ? $this->activeProperties->inspectSourceCodes($data['codes'], $credential)
            : collect();
        $knownSourceCodes = $action === 'publish'
            ? $this->activeProperties->sourceCodes()?->flip()
            : collect();
        abort_if($knownSourceCodes === null, 503, 'No fue posible verificar el inventario de Ciencuadras.');
        $legacySourceCodes = $action === 'publish'
            ? $this->activeProperties->legacySourceCodes()?->flip()
            : collect();
        abort_if($legacySourceCodes === null, 503, 'No fue posible verificar los códigos anteriores de Ciencuadras.');

        $payloads = [];
        $properties = [];
        $rejected = [];
        $skipped = [];

        foreach ($data['codes'] as $value) {
            $code = trim((string) $value);

            if ($action === 'publish' && $knownSourceCodes->has($code)) {
                $skipped[] = [
                    'code' => $code,
                    'message' => 'El código ya existe en Ciencuadras; usa Actualizar.',
                ];

                continue;
            }

            if ($action === 'publish' && $legacySourceCodes->has($code)) {
                $rejected[] = [
                    'code' => $code,
                    'message' => 'Todavía existe una publicación con código P. Retírala antes de publicar el código limpio.',
                ];

                continue;
            }

            $inspection = $inspectedCodes->get($code);
            if (in_array($action, ['update', 'pause'], true)
                && ($inspection['state'] ?? 'unavailable') !== 'active') {
                $notice = [
                    'code' => $code,
                    'message' => ($inspection['state'] ?? null) === 'unavailable'
                        ? 'No fue posible verificar este inmueble en Ciencuadras.'
                        : 'Ciencuadras no lo reporta activo; no se modificó.',
                ];
                if (($inspection['state'] ?? null) === 'unavailable') {
                    $rejected[] = $notice;
                } else {
                    $skipped[] = $notice;
                }

                continue;
            }

            try {
                $mapped = $this->mapper->fromCode($code, $targetStatus);
                if ($action !== 'publish') {
                    $mapped['payload']['propertyCode'] = $this->payloadCodeForExistingListing(
                        $mapped['property']->id,
                        (string) $mapped['payload']['propertyCode']
                    );
                }

                if ($mapped['errors']) {
                    $rejected[] = [
                        'code' => $code,
                        'message' => implode(' ', $mapped['errors']),
                    ];

                    continue;
                }

                $payloads[] = $mapped['payload'];
                $properties[] = [
                    'code' => $code,
                    'property' => $mapped['property'],
                    'external_code' => $this->consultCodeFromPayload(
                        (string) $mapped['payload']['propertyCode']
                    ),
                ];
            } catch (\Throwable $exception) {
                $rejected[] = [
                    'code' => $code,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($payloads === []) {
            return response()->json(['Datos' => [
                'ok' => false,
                'accepted' => 0,
                'rejected' => $rejected,
                'skipped' => $skipped,
            ]], $rejected === [] ? 200 : 422);
        }

        $result = $action === 'publish'
            ? $this->cc->insertProperty($payloads, $credential)
            : $this->cc->updateProperty($payloads, $credential);
        $idRequest = ($result['ok'] ?? false)
            ? $this->cc->extractIdRequest($result['data'] ?? [])
            : null;
        $accepted = (bool) ($result['ok'] ?? false) && $idRequest;
        $syncStatus = $accepted ? 'pending' : 'error';
        $response = [
            'target_action' => $action,
            'target_status' => $targetStatus,
            'batch' => true,
            'codes' => collect($properties)->pluck('code')->values()->all(),
            'request' => $result['data'] ?? null,
        ];
        $items = [];
        if (! $accepted) {
            $portalMessage = $this->errorMessage($response);
            foreach ($properties as $item) {
                $rejected[] = [
                    'code' => $item['code'],
                    'message' => $portalMessage,
                ];
            }
        }

        foreach ($properties as $item) {
            $this->saveStatus(
                $item['property']->id,
                $syncStatus,
                $item['external_code'],
                $response,
                $accepted ? null : $this->errorMessage($response)
            );
            $items[] = [
                'code' => $item['code'],
                'external_code' => $item['external_code'],
                'sync_status' => $syncStatus,
                'id_request' => $idRequest,
            ];
        }

        return response()->json(['Datos' => [
            'ok' => $accepted,
            'portal' => 'ciencuadras',
            'action' => $action,
            'environment' => config('portals.ciencuadras.environment'),
            'accepted' => $accepted ? count($items) : 0,
            'rejected' => $rejected,
            'skipped' => $skipped,
            'id_request' => $idRequest,
            'items' => $items,
            'response' => $result['data'] ?? null,
        ]], $accepted ? 202 : 422);
    }

    protected function send(Request $request, string $code, string $action, string $status): JsonResponse
    {
        if ($action === 'publish') {
            $legacyState = $this->activeProperties->inspectLegacyCode(
                $this->activeProperties->legacyCodeForSource($code),
                fresh: true
            );
            abort_if($legacyState === null, 503, 'No fue posible verificar el código anterior en Ciencuadras.');
            abort_if(
                $legacyState['state'] === 'active',
                409,
                'Este inmueble todavía tiene un código P activo en Ciencuadras. Elimínalo y verifica la baja antes de publicar el código limpio.'
            );
        }

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
        $reportedCode = $this->extractPropertyCode($statusResult['data'] ?? null);
        $consultCode = $reportedCode
            ? $this->mapper->lookupCode($reportedCode)
            : $this->consultCodeFromPayload((string) $mapped['payload']['propertyCode']);
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
            $syncStatus === 'error' ? $this->errorMessage($response) : null
        );

        if ($syncStatus === 'synced') {
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
            'public_url' => $this->publicUrlForStatus($syncStatus, $status, $response),
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
            'external_url' => $this->publicUrlForStatus($syncStatus, $response['target_status'] ?? null, $response, $fallbackUrl ?: $status->external_url),
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

            if ($this->responseHasInactive($propertyResult['data'] ?? null)
                || $this->responseHasNotFound($propertyResult['data'] ?? null)) {
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

        if ($this->responseHasActive($propertyResult['data'] ?? null)) {
            return 'synced';
        }

        if ($this->responseIsPending($statusResult['data'] ?? null)
            || $this->responseHasSuccess($statusResult['data'] ?? null)
            || $this->responseHasNotFound($propertyResult['data'] ?? null)) {
            return 'pending';
        }

        if ($this->responseHasInactive($propertyResult['data'] ?? null)) {
            return 'error';
        }

        return 'pending';
    }

    protected function verifiedSyncState(?array $statusResult, array $propertyResult, ?string $currentStatus, ?string $targetStatus = null): string
    {
        $statusData = $statusResult['data'] ?? null;
        $propertyData = $propertyResult['data'] ?? null;

        if ($targetStatus === 'I' || $targetStatus === 'D' || $currentStatus === 'paused') {
            if ($this->responseHasError($statusData)) {
                return 'error';
            }

            if ($this->responseIsPending($statusData)) {
                return 'pending';
            }

            if ($this->responseHasInactive($propertyData)
                || $this->responseHasNotFound($propertyData)) {
                return 'paused';
            }

            if (! ($propertyResult['ok'] ?? false)) {
                return 'error';
            }

            return 'pending';
        }

        if ($this->responseHasError($statusData)) {
            return 'error';
        }

        if ($this->responseHasActive($propertyData)) {
            return 'synced';
        }

        if ($this->responseIsPending($statusData)
            || $this->responseHasSuccess($statusData)
            || $this->responseHasNotFound($propertyData)) {
            return 'pending';
        }

        if ($this->responseHasInactive($propertyData)) {
            return 'error';
        }

        if ($this->responseHasError($propertyData) || ! ($propertyResult['ok'] ?? false)) {
            return 'error';
        }

        return 'pending';
    }

    protected function consultCodeForStatus(?PropertySyncStatus $status, string $default): string
    {
        $existingCode = $this->extractPropertyCode($status?->last_response ?? null) ?: $default;

        return $this->mapper->lookupCode($existingCode);
    }

    protected function payloadCodeForExistingListing(int $propertyId, string $default): string
    {
        $status = PropertySyncStatus::where([
            'property_id' => $propertyId,
            'integration_id' => $this->integration()->id,
            'environment' => config('portals.ciencuadras.environment'),
        ])->first();
        $existingCode = $this->extractPropertyCode($status?->last_response ?? null);

        if ($existingCode) {
            return $this->mapper->portalPropertyCode($existingCode);
        }

        return $this->mapper->portalPropertyCode($default);
    }

    protected function consultCodeFromPayload(string $payloadCode): string
    {
        return $this->mapper->lookupCode($payloadCode);
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

    protected function responseHasActive($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return ! $this->responseHasInactive($data)
            && (str_contains($json, '"active":"activo"')
                || str_contains($json, '"status":"0"'));
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

    protected function responseHasInactive($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, 'eliminado')
            || str_contains($json, 'inactivo')
            || str_contains($json, '"active":"eliminado"')
            || str_contains($json, '"status":"8"');
    }

    protected function extractPublicUrl(array $response): ?string
    {
        foreach ($response as $value) {
            if (is_scalar($value)) {
                $text = str_replace('\\/', '/', (string) $value);
                if (preg_match('/https?:\/\/(?:pre\.)?ciencuadras\.com\/inmueble\/[^\s"]*/i', $text, $matches)) {
                    return rtrim($matches[0], '\\');
                }
            }

            if (is_array($value) && $found = $this->extractPublicUrl($value)) {
                return $found;
            }
        }

        return null;
    }

    protected function publicUrlForStatus(string $syncStatus, ?string $targetStatus, array $response, ?string $fallbackUrl = null): ?string
    {
        if (in_array($syncStatus, ['paused', 'not_synced'], true) || in_array($targetStatus, ['I', 'D'], true)) {
            return null;
        }

        return $this->extractPublicUrl($response) ?: $fallbackUrl;
    }

    protected function propertyWebUrl(string $code): string
    {
        return 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-'.rawurlencode($code);
    }

    protected function verificationResponse(?string $idRequest, mixed $targetAction, mixed $targetStatus, mixed $statusData, mixed $propertyData): array
    {
        return [
            'idRequest' => $idRequest,
            'target_action' => is_scalar($targetAction) ? (string) $targetAction : null,
            'target_status' => is_scalar($targetStatus) ? (string) $targetStatus : null,
            'status_check' => $statusData,
            'property_check' => $propertyData,
            'checked_at' => now()->toISOString(),
        ];
    }

    protected function responseValue(array $response, string $key): mixed
    {
        if (array_key_exists($key, $response)) {
            return $response[$key];
        }

        $previous = $response['previous'] ?? null;

        return is_array($previous) ? $this->responseValue($previous, $key) : null;
    }

    protected function errorMessage(array $response): string
    {
        return substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 2000);
    }
}
