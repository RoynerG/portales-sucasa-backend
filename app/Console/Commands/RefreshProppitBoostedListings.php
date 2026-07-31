<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\PropertySyncStatus;
use App\Services\Portals\ProppitClient;
use App\Services\Portals\ProppitPropertyMapper;
use Illuminate\Console\Command;
use Throwable;

class RefreshProppitBoostedListings extends Command
{
    protected $signature = 'proppit:refresh-boosted
        {--limit= : Cantidad máxima de publicaciones a actualizar}
        {--code=* : Código(s) específicos a actualizar}
        {--dry-run : Solo muestra qué enviaría, sin tocar Proppit}';

    protected $description = 'Actualiza semanalmente la marca isBoosted de inmuebles publicados en Proppit.';

    public function handle(ProppitClient $client, ProppitPropertyMapper $mapper): int
    {
        $boostedLimit = max(0, (int) config('portals.proppit.boosted_weekly_limit', 0));
        if ($boostedLimit === 0) {
            $this->info('Rotación Proppit apagada. Configura PROPPIT_BOOSTED_WEEKLY_LIMIT con tus cupos.');
            return self::SUCCESS;
        }

        $integration = Integration::where('slug', 'proppit')->first();
        if (! $integration) {
            $this->warn('No existe la integración proppit.');
            return self::SUCCESS;
        }

        $codes = collect((array) $this->option('code'))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
        $limit = max(1, (int) ($this->option('limit') ?: config('portals.proppit.boosted_refresh_limit', 300)));
        $dryRun = (bool) $this->option('dry-run');
        $weeklyBoosted = $mapper->boostedCodes($boostedLimit);

        $query = PropertySyncStatus::query()
            ->with('property')
            ->where('integration_id', $integration->id)
            ->where('environment', 'production')
            ->where('sync_status', 'synced')
            ->whereNotNull('external_id');

        if ($codes->isNotEmpty()) {
            $query->whereHas('property', fn ($propertyQuery) => $propertyQuery->whereIn('code', $codes->all()));
        } else {
            $query->limit($limit);
        }

        $statuses = $query->get();
        if ($statuses->isEmpty()) {
            $this->info('No hay publicaciones Proppit sincronizadas para refrescar.');
            return self::SUCCESS;
        }

        $token = null;
        if (! $dryRun) {
            $login = $client->token();
            $token = $login['data']['token'] ?? null;
            if (! ($login['ok'] ?? false) || ! $token) {
                $this->error('No fue posible iniciar sesión en Proppit. Revisa PROPPIT_CLIENT_ID y PROPPIT_CLIENT_SECRET.');
                return self::FAILURE;
            }
        }

        $summary = ['boosted' => 0, 'normal' => 0, 'error' => 0];

        foreach ($statuses as $status) {
            $code = (string) ($status->property?->code ?: $status->external_id);

            try {
                $mapped = $mapper->fromCode($code);
                if ($mapped['errors']) {
                    throw new \RuntimeException(implode(' ', $mapped['errors']));
                }

                $payload = $mapped['payload'];
                $isBoosted = (bool) ($payload['isBoosted'] ?? false);
                $summary[$isBoosted ? 'boosted' : 'normal']++;
                $this->line($code.': '.($isBoosted ? 'promocionado' : 'normal'));

                if ($dryRun) {
                    continue;
                }

                $result = $client->updateAd((string) $status->external_id, $payload, $token);
                $status->update([
                    'sync_status' => ($result['ok'] ?? false) ? 'synced' : 'error',
                    'last_response' => $result,
                    'last_error' => ($result['ok'] ?? false) ? null : $this->errorMessage($result),
                    'last_attempt_at' => now(),
                    'last_synced_at' => ($result['ok'] ?? false) ? now() : $status->last_synced_at,
                    'attempts' => ((int) $status->attempts) + 1,
                ]);

                if (! ($result['ok'] ?? false)) {
                    $summary['error']++;
                    $this->warn($code.': Proppit rechazó la actualización.');
                }
            } catch (Throwable $exception) {
                $summary['error']++;
                $this->warn($code.': '.$exception->getMessage());
            }
        }

        $this->info("Proppit refrescado. Promocionados: {$summary['boosted']}; normales: {$summary['normal']}; errores: {$summary['error']}.");
        $this->line('Cupos semanales elegidos: '.implode(', ', $weeklyBoosted));

        return $summary['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function errorMessage(array $response): string
    {
        return substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000);
    }
}
