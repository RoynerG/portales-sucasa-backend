<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasClient;
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
        {--dry-run : Solo muestra qué haría, sin enviar a Ciencuadras}
        {--force : Ejecuta aunque CIENCUADRAS_AUTO_SYNC esté apagado}';

    protected $description = 'Publica, actualiza o despublica automáticamente inmuebles en Ciencuadras según la tabla WP.';

    public function handle(CiencuadrasClient $client, CiencuadrasPropertyMapper $mapper): int
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

        $rows = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->orderByDesc('cct_modified')
            ->limit($scan)
            ->get();

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

            $property = Property::where('code', $code)->first();
            $sync = $property
                ? PropertySyncStatus::where('property_id', $property->id)
                    ->where('integration_id', $integration->id)
                    ->where('environment', config('portals.ciencuadras.environment'))
                    ->first()
                : null;

            $decision = $this->decision($row, $sync);
            if (! $decision) {
                $summary['skipped']++;
                continue;
            }

            [$action, $status] = $decision;
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
                $propertyResult = $result['ok']
                    ? $client->consultProperty($mapped['payload']['propertyCode'], $credential)
                    : null;

                $response = [
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
                    $mapped['payload']['propertyCode'],
                    $response,
                    $syncStatus === 'error' ? $this->errorMessage($response) : null,
                    $this->propertyWebUrl($code)
                );

                if ($syncStatus === 'paused') {
                    $property->update(['status' => 'paused']);
                } elseif ($syncStatus === 'synced') {
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

    protected function decision(stdClass $row, ?PropertySyncStatus $sync): ?array
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

            if ($current === 'error' && ((int) $sync->attempts) < 3) {
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
            'external_url' => $this->extractPublicUrl($response) ?: $fallbackUrl ?: $status->external_url,
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
            return 'paused';
        }

        if ($this->responseHasSuccess($statusResult['data'] ?? null) || $this->responseHasSuccess($propertyResult['data'] ?? null)) {
            return 'synced';
        }

        $json = strtolower(json_encode($statusResult['data'] ?? $result['data'] ?? []));
        if (str_contains($json, 'error') || str_contains($json, 'fall')) {
            return 'error';
        }

        if (str_contains($json, 'pending')) {
            return 'pending';
        }

        return 'synced';
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
