<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\MercadoLibreClient;
use App\Services\Portals\ProppitClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class PortalCatalogAuditService
{
    public function __construct(
        protected WordPressPropertyRepository $wordpress,
        protected CiencuadrasActiveProperties $ciencuadras,
        protected FincaraizClient $fincaraiz,
        protected MercadoLibreClient $mercadolibre,
        protected ProppitClient $proppit,
    ) {}

    public function overview(?int $userId): array
    {
        $localCodes = $this->localActiveCodes();
        $portals = Integration::query()->active()->orderBy('order')->get();

        return [
            'local_active' => $localCodes->count(),
            'checked_at' => now()->toIso8601String(),
            'portals' => $portals->map(function (Integration $integration) use ($localCodes, $userId) {
                return Cache::get(
                    $this->cacheKey($integration->slug, $userId),
                    $this->emptyResult($integration, $localCodes)
                );
            })->values()->all(),
        ];
    }

    public function audit(string $portal, ?int $userId): array
    {
        $integration = Integration::query()->active()->where('slug', $portal)->firstOrFail();
        $localCodes = $this->localActiveCodes();

        try {
            $result = match ($portal) {
                'ciencuadras' => $this->auditCiencuadras($integration, $localCodes),
                'fincaraiz' => $this->auditFincaraiz($integration, $localCodes, $userId),
                'mercadolibre' => $this->auditMercadoLibre($integration, $localCodes),
                'proppit' => $this->auditProppit($integration, $localCodes),
                default => $this->unsupportedResult($integration, $localCodes),
            };
        } catch (Throwable $exception) {
            report($exception);
            $result = $this->unavailableResult(
                $integration,
                $localCodes,
                'No fue posible consultar el catálogo remoto: '.$exception->getMessage()
            );
        }

        Cache::put($this->cacheKey($portal, $userId), $result, now()->addDay());

        return $result;
    }

    protected function auditCiencuadras(Integration $integration, Collection $localCodes): array
    {
        $remoteCodes = $this->ciencuadras->sourceCodes(fresh: true);
        if ($remoteCodes === null) {
            return $this->unavailableResult($integration, $localCodes, 'Ciencuadras no devolvió su inventario.');
        }

        return $this->comparisonResult(
            $integration,
            $localCodes,
            $remoteCodes,
            $this->registryCodes($integration, config('portals.ciencuadras.environment')),
            'Inventario completo de la API',
            'Ciencuadras confirma presencia en el catálogo, pero su listado global no informa el estado activo de cada código.'
        );
    }

    protected function auditFincaraiz(Integration $integration, Collection $localCodes, ?int $userId): array
    {
        $credential = $userId ? PortalCredential::query()->where([
            'user_id' => $userId,
            'integration_id' => $integration->id,
        ])->first() : null;
        $apiKey = trim((string) ($credential?->access_token ?: config('portals.fincaraiz.api_key')));
        $clientId = trim((string) (data_get($credential?->data, 'client_id') ?: config('portals.fincaraiz.client_id')));
        if ($apiKey === '' || $clientId === '') {
            return $this->unavailableResult($integration, $localCodes, 'Falta configurar la API key o el Client ID de Fincaraíz.');
        }

        $clientResponse = $this->fincaraiz->getClients($apiKey);
        $client = collect(($clientResponse['ok'] ?? false) ? data_get($clientResponse, 'data', []) : [])
            ->filter(fn ($item) => is_array($item))
            ->first(fn (array $item) => trim((string) ($item['id'] ?? '')) === $clientId);
        $quota = $client ? [
            'initial' => is_numeric($client['initial_quota'] ?? null) ? (int) $client['initial_quota'] : null,
            'used' => is_numeric($client['used_quota'] ?? null) ? (int) $client['used_quota'] : null,
            'remaining' => is_numeric($client['remained_quota'] ?? null) ? (int) $client['remained_quota'] : null,
            'percentage_used' => is_numeric($client['percentage_used_quota'] ?? null) ? (float) $client['percentage_used_quota'] : null,
        ] : null;
        $quotaError = $quota ? null : (string) (
            data_get($clientResponse, 'data.detail')
            ?: data_get($clientResponse, 'data.error')
            ?: 'GET /client no devolvió el cliente configurado.'
        );

        $rows = collect();
        $inventoryTotal = 0;
        for ($page = 1; $page <= 100; $page++) {
            $response = $this->fincaraiz->listListings($apiKey, $clientId, $page, 100);
            if (! ($response['ok'] ?? false)) {
                throw new RuntimeException((string) (data_get($response, 'data.error') ?: 'Fincaraíz rechazó la consulta.'));
            }
            $pageRows = collect(data_get($response, 'data.results', []))
                ->filter(fn ($listing) => is_array($listing))
                ->values();
            $rows = $rows->concat($pageRows);
            $total = (int) data_get($response, 'data.count', $rows->count());
            $inventoryTotal = max($inventoryTotal, $total);
            if ($pageRows->isEmpty() || $rows->count() >= $total || ! data_get($response, 'data.next')) {
                break;
            }
        }

        $activeRows = $rows->filter(fn (array $listing) => (int) ($listing['status'] ?? -1) === 4)->values();
        $statusCounts = $rows
            ->countBy(fn (array $listing) => (string) (int) ($listing['status'] ?? -1))
            ->sortKeys()
            ->all();
        $references = $this->registryReferences($integration, config('portals.fincaraiz.environment'));
        [$remoteCodes, $unknownRemote] = $this->codesFromRemoteRows($activeRows, $references);

        $result = $this->comparisonResult(
            $integration,
            $localCodes,
            $remoteCodes,
            $references->values()->unique(),
            'Inventario activo completo de la API',
            'Solo se consideran activos los avisos con estado 4 en Fincaraíz.',
            $unknownRemote
        );

        $usedQuota = $quota['used'] ?? null;
        $activeStatusFour = $activeRows->count();
        $quotaDifference = $usedQuota === null ? null : $usedQuota - $activeStatusFour;

        $result['quota'] = $quota;
        $result['quota_error'] = $quotaError;
        $result['inventory'] = [
            'total' => max($inventoryTotal, $rows->count()),
            'loaded' => $rows->count(),
            'active_status' => 4,
            'active_status_count' => $activeStatusFour,
            'status_counts' => $statusCounts,
        ];
        $result['quota_discrepancy'] = $quotaDifference === null ? null : [
            'has_difference' => $quotaDifference !== 0,
            'difference' => $quotaDifference,
            'absolute_difference' => abs($quotaDifference),
        ];

        return $result;
    }

    protected function auditMercadoLibre(Integration $integration, Collection $localCodes): array
    {
        $credential = PortalCredential::query()->where([
            'integration_id' => $integration->id,
            'account_key' => config('portals.mercadolibre.account_key'),
        ])->first();
        if (! $credential) {
            return $this->unavailableResult($integration, $localCodes, 'La cuenta empresarial de MercadoLibre no está conectada.');
        }

        $itemIds = collect();
        $offset = 0;
        do {
            $response = $this->mercadolibre->sellerItems($credential, 'active', $offset, 50);
            if (! ($response['ok'] ?? false)) {
                throw new RuntimeException($this->mercadolibre->errorMessage($response));
            }
            $pageIds = collect(data_get($response, 'data.results', []))->map(fn ($id) => trim((string) $id))->filter();
            $itemIds = $itemIds->concat($pageIds);
            $total = (int) data_get($response, 'data.paging.total', $itemIds->count());
            $offset += 50;
        } while ($pageIds->isNotEmpty() && $itemIds->count() < $total && $offset < 10000);

        $references = $this->registryReferences($integration, config('portals.mercadolibre.environment'));
        $remoteCodes = $itemIds->map(fn (string $id) => $references->get($id))->filter()->unique()->values();
        $unknownRemote = $itemIds->reject(fn (string $id) => $references->has($id))->values();

        return $this->comparisonResult(
            $integration,
            $localCodes,
            $remoteCodes,
            $references->values()->unique(),
            'Inventario activo completo de la API',
            'Se consultan todos los avisos activos de la cuenta empresarial de MercadoLibre.',
            $unknownRemote
        );
    }

    protected function auditProppit(Integration $integration, Collection $localCodes): array
    {
        $tokenResult = $this->proppit->token();
        $token = data_get($tokenResult, 'data.token');
        if (! ($tokenResult['ok'] ?? false) || ! is_string($token) || $token === '') {
            return $this->unavailableResult($integration, $localCodes, 'No fue posible iniciar sesión en Proppit.');
        }

        $references = $this->registryReferences($integration, 'production');
        $responses = $this->proppit->getAds($references->keys()->all(), $token, 10);
        $activeReferences = collect($responses)
            ->filter(fn (array $response) => ($response['ok'] ?? false) && $this->remoteRecordIsActive($response['data'] ?? []))
            ->keys();
        $remoteCodes = $activeReferences->map(fn (string $reference) => $references->get($reference))->filter()->unique()->values();
        $failed = collect($responses)->filter(fn (array $response) => ! ($response['ok'] ?? false))->count();

        return $this->comparisonResult(
            $integration,
            $localCodes,
            $remoteCodes,
            $references->values()->unique(),
            'Verificación de todas las referencias conocidas',
            'Proppit no ofrece un listado global: se verifica en vivo cada referencia registrada localmente. No es posible detectar anuncios externos desconocidos.',
            collect(),
            true,
            $failed
        );
    }

    protected function comparisonResult(
        Integration $integration,
        Collection $localCodes,
        Collection $remoteCodes,
        Collection $registryCodes,
        string $coverage,
        string $note,
        ?Collection $unknownRemote = null,
        bool $partial = false,
        int $failedChecks = 0
    ): array {
        $remoteCodes = $this->normalizeCodes($remoteCodes);
        $registryCodes = $this->normalizeCodes($registryCodes);
        $matched = $localCodes->intersect($remoteCodes)->values();
        $missing = $localCodes->diff($remoteCodes)->values();
        $extra = $remoteCodes->diff($localCodes)->values();
        $inactive = $registryCodes->diff($remoteCodes)->values();
        $unknownRemote ??= collect();
        $hasDifferences = $missing->isNotEmpty() || $extra->isNotEmpty() || $unknownRemote->isNotEmpty() || $failedChecks > 0;

        return [
            'portal' => $integration->slug,
            'name' => $integration->name,
            'icon' => $integration->icon,
            'status' => $hasDifferences ? 'differences' : ($partial ? 'partial' : 'coordinated'),
            'status_label' => $hasDifferences ? 'Con diferencias' : ($partial ? 'Verificación parcial' : 'Coordinado'),
            'coverage' => $coverage,
            'note' => $note,
            'local_active' => $localCodes->count(),
            'registry_count' => $registryCodes->count(),
            'remote_active' => $remoteCodes->count(),
            'matched' => $matched->count(),
            'missing' => $missing->count(),
            'extra' => $extra->count(),
            'inactive_references' => $inactive->count(),
            'unknown_remote' => $unknownRemote->count(),
            'failed_checks' => $failedChecks,
            'partial' => $partial,
            'checked_at' => now()->toIso8601String(),
            'details' => [
                'missing' => $missing->take(250)->all(),
                'extra' => $extra->take(250)->all(),
                'inactive' => $inactive->take(250)->all(),
                'unknown_remote' => $unknownRemote->take(250)->all(),
            ],
        ];
    }

    protected function emptyResult(Integration $integration, Collection $localCodes): array
    {
        return [
            'portal' => $integration->slug,
            'name' => $integration->name,
            'icon' => $integration->icon,
            'status' => 'not_checked',
            'status_label' => 'Sin verificar',
            'coverage' => null,
            'note' => 'Ejecuta la auditoría para comparar el catálogo local con el portal.',
            'local_active' => $localCodes->count(),
            'registry_count' => $this->registryCodes($integration, $this->environment($integration->slug))->count(),
            'remote_active' => null,
            'matched' => null,
            'missing' => null,
            'extra' => null,
            'inactive_references' => null,
            'unknown_remote' => null,
            'failed_checks' => 0,
            'partial' => false,
            'checked_at' => null,
            'details' => ['missing' => [], 'extra' => [], 'inactive' => [], 'unknown_remote' => []],
        ];
    }

    protected function unavailableResult(Integration $integration, Collection $localCodes, string $message): array
    {
        return [
            ...$this->emptyResult($integration, $localCodes),
            'status' => 'unavailable',
            'status_label' => 'No disponible',
            'note' => $message,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    protected function unsupportedResult(Integration $integration, Collection $localCodes): array
    {
        return $this->unavailableResult($integration, $localCodes, 'Este portal todavía no tiene un verificador de catálogo.');
    }

    protected function localActiveCodes(): Collection
    {
        return $this->normalizeCodes($this->wordpress->activeCodes());
    }

    protected function registryCodes(Integration $integration, ?string $environment): Collection
    {
        return $this->registryReferences($integration, $environment)->values()->unique()->values();
    }

    protected function registryReferences(Integration $integration, ?string $environment): Collection
    {
        return PropertySyncStatus::query()
            ->where('integration_id', $integration->id)
            ->when($environment, fn ($query) => $query->where('environment', $environment))
            ->whereNotNull('external_id')
            ->with('property:id,code')
            ->get()
            ->filter(fn (PropertySyncStatus $status) => $status->property?->code && $status->external_id)
            ->mapWithKeys(fn (PropertySyncStatus $status) => [
                trim((string) $status->external_id) => trim((string) $status->property->code),
            ]);
    }

    protected function codesFromRemoteRows(Collection $rows, Collection $references): array
    {
        $codes = collect();
        $unknown = collect();

        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $code = $row['external_code']
                ?? $row['externalCode']
                ?? $row['integrator_code']
                ?? $row['integratorCode']
                ?? $references->get($id);
            if (is_scalar($code) && trim((string) $code) !== '') {
                $codes->push(trim((string) $code));
            } elseif ($id !== '') {
                $unknown->push($id);
            }
        }

        return [$codes->unique()->values(), $unknown->unique()->values()];
    }

    protected function remoteRecordIsActive(array $data): bool
    {
        $record = data_get($data, 'data', $data);
        if (! is_array($record)) {
            return true;
        }
        if (array_key_exists('active', $record) && $record['active'] === false) {
            return false;
        }
        $status = strtolower(trim((string) ($record['status'] ?? '')));

        return ! in_array($status, ['inactive', 'paused', 'deleted', 'closed', 'disabled'], true);
    }

    protected function normalizeCodes(Collection $codes): Collection
    {
        return $codes->map(fn ($code) => trim((string) $code))->filter()->unique()->sort()->values();
    }

    protected function environment(string $portal): ?string
    {
        return match ($portal) {
            'ciencuadras' => config('portals.ciencuadras.environment'),
            'fincaraiz' => config('portals.fincaraiz.environment'),
            'mercadolibre' => config('portals.mercadolibre.environment'),
            'proppit' => 'production',
            default => null,
        };
    }

    protected function cacheKey(string $portal, ?int $userId): string
    {
        return 'portal-catalog-audit:v1:'.($userId ?: 'shared').':'.$portal;
    }
}
