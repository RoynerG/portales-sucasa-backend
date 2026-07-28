<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasClient;
use Illuminate\Console\Command;

class VerifyPendingCiencuadras extends Command
{
    protected $signature = 'ciencuadras:verify-pending {--limit=25 : Cantidad máxima de inmuebles a verificar por corrida}';

    protected $description = 'Verifica automáticamente inmuebles pendientes de Ciencuadras y actualiza su estado.';

    public function handle(CiencuadrasClient $client): int
    {
        $integration = Integration::where('slug', 'ciencuadras')->first();
        if (! $integration) {
            $this->warn('No existe la integración ciencuadras.');
            return self::SUCCESS;
        }

        $pending = PropertySyncStatus::query()
            ->with('property')
            ->where('integration_id', $integration->id)
            ->where('environment', config('portals.ciencuadras.environment'))
            ->whereIn('sync_status', ['pending', 'syncing'])
            ->orderByRaw('COALESCE(last_attempt_at, created_at) ASC')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No hay inmuebles pendientes en Ciencuadras.');
            return self::SUCCESS;
        }

        $credential = $this->credential($client);
        if (! $credential) {
            $this->error('No fue posible iniciar sesión en Ciencuadras. Revisa CIENCUADRAS_USERNAME y CIENCUADRAS_PASSWORD.');
            return self::FAILURE;
        }

        $summary = ['synced' => 0, 'pending' => 0, 'error' => 0, 'skipped' => 0];

        foreach ($pending as $status) {
            if (! $status->property) {
                $summary['skipped']++;
                continue;
            }

            $code = (string) $status->property->code;
            $externalCode = $this->lookupCode($status->external_id ?: $code);
            $lastResponse = $status->last_response ?? [];
            $idRequest = $client->extractIdRequest($lastResponse);
            $targetStatus = $lastResponse['target_status'] ?? null;

            $statusResult = $idRequest
                ? $client->consultStatus(['idRequest' => $idRequest], $credential)
                : null;
            $propertyResult = $client->consultProperty($externalCode, $credential);

            $response = [
                'previous' => $lastResponse,
                'target_action' => $lastResponse['target_action'] ?? null,
                'target_status' => $targetStatus,
                'status_check' => $statusResult['data'] ?? null,
                'property_check' => $propertyResult['data'] ?? null,
            ];
            $syncStatus = $this->syncState($statusResult, $propertyResult, $status->sync_status, $targetStatus);
            $error = $syncStatus === 'error'
                ? substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000)
                : null;

            $status->fill([
                'sync_status' => $syncStatus,
                'external_id' => $externalCode,
                'external_url' => $this->publicUrlForStatus($syncStatus, $targetStatus, $response, $status->external_url ?: $this->propertyWebUrl($code)),
                'last_response' => $response,
                'last_error' => $error,
                'last_attempt_at' => now(),
                'last_synced_at' => $syncStatus === 'synced' ? now() : $status->last_synced_at,
                'attempts' => ((int) $status->attempts) + 1,
            ]);
            $status->save();

            if ($syncStatus === 'synced') {
                $status->property->update(['status' => 'active', 'published_at' => $status->property->published_at ?: now()]);
            }

            $summary[$syncStatus] = ($summary[$syncStatus] ?? 0) + 1;
            $this->line("{$code}: {$syncStatus}");
        }

        $this->info("Listo. Publicados: {$summary['synced']} | Pendientes: {$summary['pending']} | Errores: {$summary['error']} | Omitidos: {$summary['skipped']}");

        return self::SUCCESS;
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

    protected function syncState(?array $statusResult, array $propertyResult, ?string $currentStatus, ?string $targetStatus = null): string
    {
        $statusData = $statusResult['data'] ?? null;
        $propertyData = $propertyResult['data'] ?? null;

        if ($targetStatus === 'I' || $targetStatus === 'D' || $currentStatus === 'paused') {
            if ($this->responseIsPending($statusData)) {
                return 'pending';
            }

            if ($this->responseHasSuccess($statusData) || $this->responseHasInactive($propertyData) || $this->responseHasNotFound($propertyData)) {
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
        $json = json_encode($response);
        if (! $json) {
            return null;
        }

        if (preg_match('/https?:\\\\?\\/\\\\?\\/[^"\\s]+ciencuadras\\.com[^"\\s]*/i', $json, $matches)) {
            return str_replace('\\/', '/', $matches[0]);
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

    protected function lookupCode(string $code): string
    {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix');
        $code = trim($code);

        while ($prefix !== '' && str_starts_with($code, $prefix)) {
            $code = substr($code, strlen($prefix));
        }

        if ($prefix !== '' && str_contains($code, $prefix)) {
            $code = substr($code, strrpos($code, $prefix) + strlen($prefix));
        }

        return $prefix === '' ? $code : $prefix . $code;
    }

    protected function propertyWebUrl(string $code): string
    {
        return 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-' . rawurlencode($code);
    }
}
