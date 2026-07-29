<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class AutoSyncCiencuadras extends Command
{
    protected $signature = 'ciencuadras:auto-sync
        {--limit= : Cantidad máxima de acciones a ejecutar}
        {--scan= : Cantidad máxima de inmuebles WP a revisar}
        {--code=* : Código(s) específicos a procesar}
        {--retry-errors : Reintenta inmuebles en error si siguen públicos en WordPress}
        {--dry-run : Solo muestra qué haría, sin enviar a Ciencuadras}
        {--force : Ejecuta aunque CIENCUADRAS_AUTO_SYNC esté apagado}';

    protected $description = 'Publica, actualiza o despublica automáticamente inmuebles en Ciencuadras según la tabla WP.';

    public function handle(
        CiencuadrasClient $client,
        CiencuadrasPropertyMapper $mapper,
        CiencuadrasActiveProperties $activeProperties
    ): int
    {
        if (! config('portals.ciencuadras.auto_sync') && ! $this->option('force')) {
            $this->info('Auto-sync de Ciencuadras apagado. Actívalo con CIENCUADRAS_AUTO_SYNC=true.');
            return self::SUCCESS;
        }

        $integration = Integration::where('slug', 'ciencuadras')->first();
        if (! $integration) {
            $this->warn('No existe la integración ciencuadras.');
            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('portals.ciencuadras.auto_sync_limit', 20)));
        $scan = max($limit, (int) ($this->option('scan') ?: config('portals.ciencuadras.auto_sync_scan', 500)));
        $dryRun = (bool) $this->option('dry-run');

        $credential = $dryRun ? null : $this->credential($client);
        if (! $dryRun && ! $credential) {
            $this->error('No fue posible iniciar sesión en Ciencuadras. Revisa credenciales.');
            return self::FAILURE;
        }

        $codes = collect((array) $this->option('code'))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        $rowsQuery = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->select(['codigo', 'estado', 'fecha_actualizacion', 'cct_modified'])
            ->where('cct_status', 'publish');

        if ($codes->isNotEmpty()) {
            $rowsQuery->whereIn('codigo', $codes->all());
        } else {
            $rowsQuery->orderByDesc('cct_modified')->limit($scan);
        }

        $rows = $rowsQuery->get();
        $rowCodes = $rows
            ->pluck('codigo')
            ->map(fn ($code) => (string) $code)
            ->filter()
            ->unique()
            ->values();
        $properties = Property::query()
            ->whereIn('code', $rowCodes->all())
            ->get()
            ->keyBy(fn (Property $property) => (string) $property->code);
        $syncs = PropertySyncStatus::query()
            ->whereIn('property_id', $properties->pluck('id')->all())
            ->where('integration_id', $integration->id)
            ->where('environment', config('portals.ciencuadras.environment'))
            ->get()
            ->keyBy('property_id');

        $executed = 0;
        $summary = ['publish' => 0, 'update' => 0, 'pause' => 0, 'skipped' => 0, 'error' => 0];

        foreach ($rows as $row) {
            if ($executed >= $limit) {
                break;
            }

            $code = (string) $row->codigo;
            if ($code === '') {
                $summary['skipped']++;
                continue;
            }

            $property = $properties->get($code);
            $sync = $property ? $syncs->get($property->id) : null;

            $decision = $this->decision($row, $sync, (bool) $this->option('retry-errors'));
            if (! $decision) {
                $summary['skipped']++;
                continue;
            }

            [$action, $status] = $decision;

            if ($action === 'publish' && ! $dryRun) {
                $legacyState = $activeProperties->inspectLegacyCode(
                    $activeProperties->legacyCodeForSource($code),
                    $credential,
                    true
                );
                if ($legacyState === null) {
                    $this->warn("{$code}: omitido; no fue posible verificar la publicación anterior.");
                    $summary['skipped']++;
                    continue;
                }
                if ($legacyState['state'] === 'active') {
                    $this->warn("{$code}: bloqueado; todavía existe una publicación anterior en Ciencuadras.");
                    $summary['skipped']++;
                    continue;
                }
            }

            $this->line("{$code}: {$action}");

            if ($dryRun) {
                $summary[$action]++;
                $executed++;
                continue;
            }

            try {
                $mapped = $mapper->fromCode($code, $status);
                $property = $mapped['property'];

                if ($mapped['errors']) {
                    $this->saveStatus(
                        $property->id,
                        $integration->id,
                        'error',
                        $mapped['payload']['propertyCode'],
                        ['validation_errors' => $mapped['errors'], 'source' => $mapped['source']],
                        implode(' ', $mapped['errors'])
                    );
                    $summary['error']++;
                    $executed++;
                    continue;
                }

                $result = $action === 'publish'
                    ? $client->insertProperty($mapped['payload'], $credential)
                    : $client->updateProperty($mapped['payload'], $credential);

                $idRequest = $result['ok'] ? $client->extractIdRequest($result['data'] ?? []) : null;
                $statusResult = $idRequest
                    ? $client->consultStatus(['idRequest' => $idRequest], $credential)
                    : null;
                $externalCode = $mapper->lookupCode($mapped['payload']['propertyCode']);
                $propertyResult = $result['ok']
                    ? $client->consultProperty($externalCode, $credential)
                    : null;

                $response = [
                    'target_action' => $action,
                    'target_status' => $status,
                    'request' => $result['data'],
                    'status_check' => $statusResult['data'] ?? null,
                    'property_check' => $propertyResult['data'] ?? null,
                    'auto_action' => $action,
                ];
                $syncStatus = $this->syncState($result, $statusResult, $status, $propertyResult);

                $this->saveStatus(
                    $property->id,
                    $integration->id,
                    $syncStatus,
                    $externalCode,
                    $response,
                    $syncStatus === 'error' ? $this->errorMessage($response) : null
                );

                if ($syncStatus === 'synced') {
                    $property->update(['status' => 'active', 'published_at' => $property->published_at ?: now()]);
                }

                $summary[$action]++;
                $executed++;
            } catch (\Throwable $e) {
                $summary['error']++;
                $this->error("{$code}: {$e->getMessage()}");
            }
        }

        $this->info("Listo. Publicar: {$summary['publish']} | Actualizar: {$summary['update']} | Despublicar: {$summary['pause']} | Omitidos: {$summary['skipped']} | Errores: {$summary['error']}");

        return self::SUCCESS;
    }

    protected function decision(stdClass $row, ?PropertySyncStatus $sync, bool $retryErrors = false): ?array
    {
        $isPublic = $this->isPublicStatus($row->estado);
        $current = $sync?->sync_status;

        if (in_array($current, ['pending', 'syncing'], true)) {
            return null;
        }

        if ($isPublic) {
            if (! $sync || in_array($current, [null, 'not_synced'], true)) {
                return ['publish', 'A'];
            }

            if ($current === 'paused') {
                return ['update', 'A'];
            }

            if ($current === 'error' && ($retryErrors || ((int) $sync->attempts) < 3)) {
                return ['publish', 'A'];
            }

            $modifiedAt = $this->wpModifiedAt($row);
            $lastSyncedAt = $sync->last_synced_at ?? $sync->updated_at;
            if ($current === 'synced' && (! $lastSyncedAt || ($modifiedAt && $modifiedAt->gt($lastSyncedAt)))) {
                return ['update', 'A'];
            }

            return null;
        }

        if (in_array($current, ['synced', 'pending', 'syncing'], true)) {
            return ['pause', 'I'];
        }

        return null;
    }

    protected function isPublicStatus(?string $status): bool
    {
        $status = Str::ascii(strtolower(trim((string) $status)));

        return in_array($status, ['publico', 'publicado'], true);
    }

    protected function wpModifiedAt(stdClass $row): ?Carbon
    {
        $value = $row->fecha_actualizacion ?: $row->cct_modified ?: null;
        if (! $value) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function credential(CiencuadrasClient $client): ?PortalCredential
    {
        $result = $client->login([
            'username' => config('portals.ciencuadras.username'),
            'password' => config('portals.ciencuadras.password'),
        ]);

        $token = $result['ok'] ? $client->extractToken($result['data'] ?? []) : null;

        return $token ? new PortalCredential(['access_token' => $token]) : null;
    }

    protected function saveStatus(
        int $propertyId,
        int $integrationId,
        string $syncStatus,
        string $externalId,
        array $response,
        ?string $error,
        ?string $fallbackUrl = null
    ): void {
        $status = PropertySyncStatus::firstOrNew([
            'property_id' => $propertyId,
            'integration_id' => $integrationId,
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

        if ($targetStatus === 'I') {
            $json = strtolower(json_encode($statusResult['data'] ?? $result['data'] ?? []));
            if (str_contains($json, 'error') || str_contains($json, 'fall')) {
                return 'error';
            }

            if ($this->responseIsPending($statusResult['data'] ?? null)) {
                return 'pending';
            }

            if ($this->responseHasInactive($propertyResult['data'] ?? null)
                || $this->responseHasNotFound($propertyResult['data'] ?? null)) {
                return 'paused';
            }

            return 'pending';
        }

        $json = strtolower(json_encode($statusResult['data'] ?? $result['data'] ?? []));
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

    protected function responseIsPending($data): bool
    {
        return str_contains(strtolower(json_encode($data ?? [])), 'pending');
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
        if ($syncStatus === 'paused' || in_array($targetStatus, ['I', 'D'], true)) {
            return null;
        }

        return $this->extractPublicUrl($response) ?: $fallbackUrl;
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
