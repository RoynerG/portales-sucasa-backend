<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CiencuadrasLegacyOperation;
use App\Models\PortalCredential;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CiencuadrasLegacyController extends Controller
{
    public function __construct(
        protected CiencuadrasClient $client,
        protected CiencuadrasPropertyMapper $mapper,
        protected CiencuadrasActiveProperties $activeProperties,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $codes = $this->portalCodes($request->boolean('fresh'));
        $this->syncDetectedOperations($codes);

        return response()->json(['Datos' => $this->listingPayload($request, $codes)]);
    }

    public function deleteSelected(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codes' => ['required', 'array', 'min:1', 'max:20'],
            'codes.*' => ['required', 'string', 'distinct', 'max:40'],
        ]);

        $portalCodes = $this->portalCodes(true);
        $detectedLegacy = $portalCodes
            ->filter(fn (string $code) => $this->activeProperties->isLegacyCode($code))
            ->flip();
        $payloads = [];
        $targets = [];
        $errors = [];
        $alreadyDeleted = 0;
        $credential = $this->credential();

        foreach ($data['codes'] as $value) {
            $legacyCode = trim((string) $value);

            if (! $this->validLegacyCode($legacyCode) || ! $detectedLegacy->has($legacyCode)) {
                $errors[] = [
                    'legacy_code' => $legacyCode,
                    'message' => 'Ciencuadras ya no reporta este código P en el inventario.',
                ];

                continue;
            }

            $sourceCode = $this->activeProperties->sourceCode($legacyCode);

            try {
                $inspection = $this->activeProperties->inspectLegacyCode($legacyCode, $credential, true);
                if ($inspection === null) {
                    throw new \RuntimeException('No fue posible consultar el estado actual en Ciencuadras.');
                }

                if (in_array($inspection['state'], ['inactive', 'missing'], true)) {
                    CiencuadrasLegacyOperation::updateOrCreate(
                        ['legacy_code' => $legacyCode],
                        [
                            'source_code' => $sourceCode,
                            'status' => 'deleted',
                            'last_response' => $inspection['response'],
                            'last_error' => null,
                            'verified_at' => now(),
                        ]
                    );
                    $alreadyDeleted++;

                    continue;
                }

                if ($inspection['state'] !== 'active') {
                    throw new \RuntimeException('Ciencuadras devolvió un estado que no permite confirmar si el inmueble sigue activo.');
                }

                $mapped = $this->mapper->fromCode($sourceCode, 'D');
                if ($mapped['errors']) {
                    throw new \RuntimeException(implode(' ', $mapped['errors']));
                }

                $payload = $mapped['payload'];
                $payload['propertyCode'] = $legacyCode;
                $payload['status'] = 'D';
                $payloads[] = $payload;
                $targets[] = compact('legacyCode', 'sourceCode');
            } catch (\Throwable $exception) {
                $this->saveError($legacyCode, $sourceCode, $exception->getMessage(), $request);
                $errors[] = [
                    'legacy_code' => $legacyCode,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($payloads === []) {
            if ($alreadyDeleted > 0 && $errors === []) {
                return response()->json(['Datos' => [
                    'ok' => true,
                    'accepted' => 0,
                    'already_deleted' => $alreadyDeleted,
                    'message' => "{$alreadyDeleted} códigos ya estaban eliminados; no fue necesario reenviar la baja.",
                    'errors' => [],
                ]]);
            }

            return response()->json(['Datos' => [
                'ok' => false,
                'accepted' => 0,
                'errors' => $errors,
            ]], 422);
        }

        $result = $this->client->updateProperty($payloads, $credential);
        $idRequest = ($result['ok'] ?? false)
            ? $this->client->extractIdRequest($result['data'] ?? [])
            : null;
        $accepted = (bool) ($result['ok'] ?? false) && $idRequest;
        $errorMessage = $accepted
            ? null
            : $this->readableApiError($result['data'] ?? null);

        foreach ($targets as $target) {
            CiencuadrasLegacyOperation::updateOrCreate(
                ['legacy_code' => $target['legacyCode']],
                [
                    'source_code' => $target['sourceCode'],
                    'status' => $accepted ? 'delete_pending' : 'error',
                    'id_request' => $idRequest,
                    'last_response' => $result['data'] ?? null,
                    'last_error' => $errorMessage,
                    'requested_by' => $request->user()?->id,
                    'requested_at' => now(),
                    'verified_at' => null,
                ]
            );
        }

        if (! $accepted) {
            $errors[] = [
                'legacy_code' => null,
                'message' => $errorMessage,
            ];
        }

        return response()->json(['Datos' => [
            'ok' => $accepted,
            'accepted' => $accepted ? count($targets) : 0,
            'already_deleted' => $alreadyDeleted,
            'id_request' => $idRequest,
            'message' => $accepted
                ? 'Baja enviada. Ciencuadras puede tardar hasta 20 minutos en aplicarla.'
                : 'Ciencuadras no aceptó la baja.',
            'errors' => $errors,
            'response' => $result['data'] ?? null,
        ]], $accepted ? 202 : 422);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codes' => ['nullable', 'array', 'max:20'],
            'codes.*' => ['required', 'string', 'distinct', 'max:40'],
        ]);

        $portalCodes = $this->portalCodes(true);
        $query = CiencuadrasLegacyOperation::query()
            ->whereIn('status', ['detected', 'delete_pending', 'error']);

        if (! empty($data['codes'])) {
            $query->whereIn('legacy_code', $data['codes']);
        } else {
            $query->where('status', 'delete_pending');
        }

        $verified = 0;
        $deleted = 0;
        $active = 0;
        $credential = $this->credential();

        foreach ($query->limit(20)->get() as $operation) {
            $verified++;
            $inspection = $this->activeProperties->inspectLegacyCode(
                $operation->legacy_code,
                $credential,
                true
            );

            if ($inspection === null) {
                $operation->fill([
                    'status' => 'error',
                    'last_error' => 'No fue posible consultar el estado actual en Ciencuadras.',
                    'verified_at' => now(),
                ])->save();

                continue;
            }

            if (in_array($inspection['state'], ['inactive', 'missing'], true)) {
                $operation->fill([
                    'status' => 'deleted',
                    'last_response' => $inspection['response'],
                    'last_error' => null,
                    'verified_at' => now(),
                ])->save();
                $deleted++;

                continue;
            }

            $active++;
            $timedOut = $operation->requested_at?->lt(now()->subMinutes(25)) ?? false;
            $operation->fill([
                'status' => $timedOut
                    ? 'error'
                    : ($operation->status === 'delete_pending' ? 'delete_pending' : 'active'),
                'last_response' => $inspection['response'],
                'last_error' => $timedOut
                    ? 'Ciencuadras todavía lo reporta activo después de 25 minutos. Puedes reenviar la baja.'
                    : null,
                'verified_at' => now(),
            ])->save();
        }

        $this->syncDetectedOperations($portalCodes);

        return response()->json(['Datos' => [
            'ok' => true,
            'verified' => $verified,
            'deleted' => $deleted,
            'active' => $active,
            'remaining_detected' => $portalCodes
                ->filter(fn (string $code) => $this->activeProperties->isLegacyCode($code))
                ->count(),
            'message' => $deleted
                ? "{$deleted} códigos P ya no aparecen activos en Ciencuadras."
                : 'Ciencuadras todavía no ha confirmado nuevas bajas.',
        ]]);
    }

    protected function listingPayload(Request $request, Collection $portalCodes): array
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'all'));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));
        $activeLegacy = $portalCodes
            ->filter(fn (string $code) => $this->activeProperties->isLegacyCode($code))
            ->values();
        $cleanSet = $portalCodes
            ->reject(fn (string $code) => $this->activeProperties->isLegacyCode($code))
            ->flip();
        $operations = CiencuadrasLegacyOperation::query()
            ->orderByDesc('updated_at')
            ->get()
            ->keyBy('legacy_code');
        $allLegacyCodes = $activeLegacy
            ->merge($operations->keys())
            ->unique()
            ->values();
        $sourceCodes = $allLegacyCodes
            ->map(fn (string $code) => $this->activeProperties->sourceCode($code))
            ->unique()
            ->values();
        $properties = $this->sourceProperties($sourceCodes);
        $activeSet = $activeLegacy->flip();

        $items = $allLegacyCodes->map(function (string $legacyCode) use (
            $operations,
            $properties,
            $activeSet,
            $cleanSet
        ) {
            $sourceCode = $this->activeProperties->sourceCode($legacyCode);
            $operation = $operations->get($legacyCode);
            $isActive = $activeSet->has($legacyCode);
            $cleanCode = $this->mapper->lookupCode($sourceCode);
            $row = $properties->get($sourceCode);
            $itemStatus = $isActive
                ? match ($operation?->status) {
                    'delete_pending' => 'delete_pending',
                    'error' => 'error',
                    'active' => 'active',
                    'deleted' => 'deleted',
                    default => 'detected',
                }
            : 'deleted';

            return [
                'legacy_code' => $legacyCode,
                'source_code' => $sourceCode,
                'clean_code' => $cleanCode,
                'status' => $itemStatus,
                'active_in_portal' => in_array(
                    $itemStatus,
                    ['detected', 'active', 'delete_pending', 'error'],
                    true
                ),
                'clean_active' => $cleanSet->has($cleanCode),
                'id_request' => $operation?->id_request,
                'last_response' => $operation?->last_response,
                'last_error' => $operation?->last_error,
                'requested_at' => $operation?->requested_at?->toIso8601String(),
                'verified_at' => $operation?->verified_at?->toIso8601String(),
                'property' => [
                    'title' => $row?->title,
                    'type' => $row?->type,
                    'transaction' => $row?->transaction,
                    'city' => $row?->city,
                    'neighborhood' => $row?->neighborhood,
                    'address' => $row?->address,
                    'source_status' => $row?->source_status,
                ],
            ];
        });

        if ($status !== '' && $status !== 'all') {
            $items = $items->where('status', $status);
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = $items->filter(function (array $item) use ($needle) {
                $haystack = mb_strtolower(implode(' ', [
                    $item['legacy_code'],
                    $item['source_code'],
                    $item['property']['title'],
                    $item['property']['city'],
                    $item['property']['neighborhood'],
                    $item['property']['address'],
                ]));

                return str_contains($haystack, $needle);
            });
        }

        $items = $items
            ->sortBy(fn (array $item) => [
                ['detected' => 0, 'active' => 1, 'error' => 2, 'delete_pending' => 3, 'deleted' => 4][$item['status']] ?? 5,
                str_pad($item['source_code'], 20, '0', STR_PAD_LEFT),
            ])
            ->values();
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'environment' => config('portals.ciencuadras.environment'),
            'summary' => [
                'total' => $allLegacyCodes->count(),
                'active' => $activeLegacy->count(),
                'confirmed_active' => $operations->where('status', 'active')->count(),
                'pending' => $operations->where('status', 'delete_pending')->count(),
                'deleted' => $operations->where('status', 'deleted')->count(),
                'error' => $operations->where('status', 'error')->count(),
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? 0 : min($total, $page * $perPage),
            ],
            'items' => $items->forPage($page, $perPage)->values(),
        ];
    }

    protected function portalCodes(bool $fresh): Collection
    {
        $codes = $this->activeProperties->codes($fresh);
        abort_if($codes === null, 503, 'No fue posible consultar el inventario activo de Ciencuadras.');

        return $codes;
    }

    protected function syncDetectedOperations(Collection $portalCodes): void
    {
        $activeLegacy = $portalCodes
            ->filter(fn (string $code) => $this->activeProperties->isLegacyCode($code))
            ->values();
        $activeSet = $activeLegacy->flip();

        foreach ($activeLegacy as $legacyCode) {
            $operation = CiencuadrasLegacyOperation::firstOrNew(['legacy_code' => $legacyCode]);
            $operation->source_code = $this->activeProperties->sourceCode($legacyCode);

            if (! $operation->exists) {
                $operation->status = 'detected';
                $operation->last_error = null;
            }

            $operation->save();
        }

        CiencuadrasLegacyOperation::query()
            ->whereIn('status', ['detected', 'delete_pending'])
            ->get()
            ->reject(fn (CiencuadrasLegacyOperation $operation) => $activeSet->has($operation->legacy_code))
            ->each(function (CiencuadrasLegacyOperation $operation) {
                $operation->fill([
                    'status' => 'deleted',
                    'last_error' => null,
                    'verified_at' => now(),
                ])->save();
            });
    }

    protected function sourceProperties(Collection $codes): Collection
    {
        return DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->whereIn('codigo', $codes)
            ->get([
                'codigo',
                'tipo_inmueble',
                'tipo_negocio',
                'ciudad',
                'barrio',
                'direccion',
                'direccion_fisica',
                'estado',
            ])
            ->mapWithKeys(function ($row) {
                $type = trim((string) $row->tipo_inmueble);
                $transaction = trim((string) $row->tipo_negocio);
                $neighborhood = trim((string) $row->barrio);

                return [(string) $row->codigo => (object) [
                    'title' => trim("{$type} en {$transaction}".($neighborhood ? " - {$neighborhood}" : '')),
                    'type' => $type,
                    'transaction' => $transaction,
                    'city' => $row->ciudad,
                    'neighborhood' => $row->barrio,
                    'address' => $row->direccion ?: $row->direccion_fisica,
                    'source_status' => $row->estado,
                ]];
            });
    }

    protected function credential(): PortalCredential
    {
        $result = $this->client->login();
        $token = ($result['ok'] ?? false)
            ? $this->client->extractToken($result['data'] ?? [])
            : null;

        abort_if(! $token, 422, 'No fue posible iniciar sesión en Ciencuadras.');

        return new PortalCredential(['access_token' => $token]);
    }

    protected function validLegacyCode(string $code): bool
    {
        $prefix = preg_quote((string) config('portals.ciencuadras.property_code_prefix', '22130-'), '/');

        return preg_match('/^'.$prefix.'P\d+$/i', $code) === 1;
    }

    protected function saveError(string $legacyCode, string $sourceCode, string $message, Request $request): void
    {
        CiencuadrasLegacyOperation::updateOrCreate(
            ['legacy_code' => $legacyCode],
            [
                'source_code' => $sourceCode,
                'status' => 'error',
                'last_error' => $message,
                'requested_by' => $request->user()?->id,
                'requested_at' => now(),
            ]
        );
    }

    protected function readableApiError(mixed $data): string
    {
        $message = data_get($data, 'body.message')
            ?? data_get($data, 'message')
            ?? data_get($data, 'error')
            ?? 'La API de Ciencuadras no aceptó la solicitud.';

        return is_scalar($message) ? (string) $message : json_encode($message, JSON_UNESCAPED_UNICODE);
    }
}
