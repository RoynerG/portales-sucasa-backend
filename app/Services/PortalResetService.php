<?php

namespace App\Services;

use App\Models\PortalResetEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PortalResetService
{
    private const TABLES = [
        'property_sync_statuses',
        'mercadolibre_notifications',
        'ciencuadras_legacy_operations',
    ];

    public function preview(): array
    {
        $counts = $this->counts();
        $lastReset = Schema::hasTable('portal_reset_events')
            ? PortalResetEvent::query()->latest()->first()
            : null;

        return [
            'counts' => $counts,
            'by_portal' => $this->countsByPortal(),
            'records_to_delete' => $counts['sync_records']
                + $counts['mercadolibre_notifications']
                + $counts['ciencuadras_operations']
                + $counts['portal_cache'],
            'has_data' => collect($counts)->contains(fn (int $count): bool => $count > 0),
            'confirmation_phrase' => (string) config('portal_reset.confirmation_phrase'),
            'auto_sync' => [
                'enabled' => (bool) config('portals.ciencuadras.auto_sync'),
                'environment' => (string) config('portals.ciencuadras.environment', 'production'),
            ],
            'preserved' => [
                'Inmuebles y sus códigos internos',
                'Integraciones y credenciales de acceso',
                'Homologaciones de ciudades, barrios y características',
                'Solicitudes y destacados históricos',
                'Configuración de cada portal',
            ],
            'last_reset' => $lastReset ? [
                'user_name' => $lastReset->user_name,
                'created_at' => $lastReset->created_at?->toIso8601String(),
                'deleted_counts' => $lastReset->deleted_counts,
            ] : null,
        ];
    }

    public function reset(User $user, ?string $ipAddress = null): array
    {
        $before = $this->preview();
        $backup = $this->createBackup($user, $before);

        [$deleted, $event] = DB::transaction(function () use ($user, $ipAddress, $backup): array {
            $deleted = [
                'sync_records' => $this->deleteTable('property_sync_statuses'),
                'mercadolibre_notifications' => $this->deleteTable('mercadolibre_notifications'),
                'ciencuadras_operations' => $this->deleteTable('ciencuadras_legacy_operations'),
                'portal_cache' => $this->portalCacheQuery()?->delete() ?? 0,
            ];

            $event = PortalResetEvent::query()->create([
                'user_id' => $user->id,
                'legacy_employee_id' => $user->legacy_employee_id,
                'user_name' => $user->name,
                'deleted_counts' => $deleted,
                'backup_file' => $backup['path'],
                'backup_checksum' => $backup['checksum'],
                'ip_address' => $ipAddress,
            ]);

            return [$deleted, $event];
        });

        return [
            'message' => 'El historial de publicaciones fue reiniciado correctamente.',
            'deleted' => $deleted,
            'backup' => [
                'file' => basename($backup['path']),
                'checksum' => $backup['checksum'],
            ],
            'reset_at' => $event->created_at?->toIso8601String(),
            'preview' => $this->preview(),
        ];
    }

    private function counts(): array
    {
        $syncQuery = Schema::hasTable('property_sync_statuses')
            ? DB::table('property_sync_statuses')
            : null;

        return [
            'sync_records' => $syncQuery?->count() ?? 0,
            'external_ids' => $syncQuery ? (clone $syncQuery)->whereNotNull('external_id')->count() : 0,
            'external_urls' => $syncQuery ? (clone $syncQuery)->whereNotNull('external_url')->count() : 0,
            'pending' => $syncQuery ? (clone $syncQuery)->whereIn('sync_status', ['pending', 'syncing'])->count() : 0,
            'errors' => $syncQuery ? (clone $syncQuery)->where('sync_status', 'error')->count() : 0,
            'mercadolibre_notifications' => $this->tableCount('mercadolibre_notifications'),
            'ciencuadras_operations' => $this->tableCount('ciencuadras_legacy_operations'),
            'portal_cache' => $this->portalCacheQuery()?->count() ?? 0,
        ];
    }

    private function countsByPortal(): array
    {
        if (! Schema::hasTable('property_sync_statuses') || ! Schema::hasTable('integrations')) {
            return [];
        }

        return DB::table('property_sync_statuses as sync')
            ->join('integrations as integration', 'integration.id', '=', 'sync.integration_id')
            ->selectRaw('integration.slug, integration.name, COUNT(*) AS total')
            ->groupBy('integration.slug', 'integration.name')
            ->orderBy('integration.name')
            ->get()
            ->map(fn ($row): array => [
                'slug' => $row->slug,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function createBackup(User $user, array $preview): array
    {
        $payload = [
            'created_at' => now()->toIso8601String(),
            'created_by' => [
                'user_id' => $user->id,
                'legacy_employee_id' => $user->legacy_employee_id,
                'name' => $user->name,
            ],
            'preview' => $preview,
            'data' => [],
        ];

        foreach (self::TABLES as $table) {
            $payload['data'][$table] = Schema::hasTable($table)
                ? DB::table($table)->get()->map(fn ($row): array => (array) $row)->all()
                : [];
        }

        $payload['data']['portal_cache'] = $this->portalCacheQuery()
            ?->get()
            ->map(fn ($row): array => (array) $row)
            ->all() ?? [];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $path = 'portal-resets/portal-reset-'.now()->format('Ymd-His-u').'.json';

        if (! Storage::disk('local')->put($path, $json)) {
            throw new RuntimeException('No fue posible crear el respaldo. El reinicio fue cancelado.');
        }

        return [
            'path' => $path,
            'checksum' => hash('sha256', $json),
        ];
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function deleteTable(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->delete() : 0;
    }

    private function portalCacheQuery(): mixed
    {
        if (! Schema::hasTable('cache')) {
            return null;
        }

        return DB::table('cache')->where(function ($query): void {
            $query->where('key', 'like', '%ciencuadras%')
                ->orWhere('key', 'like', '%mercadolibre%')
                ->orWhere('key', 'like', '%proppit%')
                ->orWhere('key', 'like', '%fincaraiz%')
                ->orWhere('key', 'like', '%automation%');
        });
    }
}
