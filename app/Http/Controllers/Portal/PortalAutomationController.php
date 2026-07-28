<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PropertySyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalAutomationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $portal = trim((string) $request->query('portal', 'ciencuadras'));
        $status = trim((string) $request->query('status', 'all'));
        $action = trim((string) $request->query('action', 'all'));
        $search = trim((string) $request->query('search', ''));
        $limit = min(500, max(25, (int) $request->query('limit', 200)));

        $integrations = Integration::query()
            ->active()
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $query = PropertySyncStatus::query()
            ->with(['property', 'integration'])
            ->whereHas('integration', fn ($query) => $query->where('slug', $portal));

        if ($status !== '' && $status !== 'all') {
            $query->where('sync_status', $status);
        }

        if ($search !== '') {
            $query->whereHas('property', function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $items = $query
            ->orderByRaw('COALESCE(last_attempt_at, last_synced_at, updated_at) DESC')
            ->limit($limit)
            ->get()
            ->map(fn (PropertySyncStatus $sync) => $this->itemPayload($sync))
            ->filter(fn (array $item) => $action === 'all' || $item['action'] === $action)
            ->values();

        return response()->json(['Datos' => [
            'portal' => $portal,
            'config' => $this->configPayload($portal),
            'portals' => $integrations->map(fn (Integration $integration) => [
                'id' => $integration->id,
                'slug' => $integration->slug,
                'name' => $integration->name,
                'description' => $integration->description,
                'active' => $integration->active,
            ])->values(),
            'summary' => $this->summaryPayload($items),
            'items' => $items,
        ]]);
    }

    protected function itemPayload(PropertySyncStatus $sync): array
    {
        $response = $sync->last_response ?? [];
        $action = $this->actionFromResponse($response, $sync->sync_status);

        return [
            'id' => $sync->id,
            'portal' => $sync->integration?->slug,
            'portal_name' => $sync->integration?->name,
            'environment' => $sync->environment,
            'sync_status' => $sync->sync_status,
            'action' => $action,
            'action_label' => $this->actionLabel($action),
            'target_status' => $response['target_status'] ?? $response['previous']['target_status'] ?? null,
            'external_id' => $sync->external_id,
            'external_url' => $sync->external_url,
            'last_error' => $sync->last_error,
            'last_response' => $response,
            'last_attempt_at' => $sync->last_attempt_at?->toIso8601String(),
            'last_synced_at' => $sync->last_synced_at?->toIso8601String(),
            'attempts' => $sync->attempts,
            'property' => [
                'code' => $sync->property?->code,
                'title' => $sync->property?->title,
                'status' => $sync->property?->status,
                'address' => $sync->property?->address,
            ],
        ];
    }

    protected function actionFromResponse(array $response, ?string $syncStatus): string
    {
        $targetStatus = $response['target_status'] ?? $response['previous']['target_status'] ?? null;
        if ($targetStatus === 'I' || $syncStatus === 'paused') {
            return 'pause';
        }
        if ($targetStatus === 'D') {
            return 'delete';
        }

        $action = $response['auto_action']
            ?? $response['target_action']
            ?? $response['previous']['target_action']
            ?? null;

        return in_array($action, ['publish', 'update', 'pause', 'delete', 'verify'], true)
            ? $action
            : 'verify';
    }

    protected function actionLabel(string $action): string
    {
        return [
            'publish' => 'Publicación',
            'update' => 'Actualización',
            'pause' => 'Despublicación',
            'delete' => 'Eliminación',
            'verify' => 'Verificación',
        ][$action] ?? 'Operación';
    }

    protected function summaryPayload($items): array
    {
        $items = collect($items);

        return [
            'total' => $items->count(),
            'active' => $items->whereIn('sync_status', ['pending', 'syncing'])->count(),
            'synced' => $items->where('sync_status', 'synced')->count(),
            'paused' => $items->where('sync_status', 'paused')->count(),
            'error' => $items->where('sync_status', 'error')->count(),
            'publish' => $items->where('action', 'publish')->count(),
            'update' => $items->where('action', 'update')->count(),
            'pause' => $items->where('action', 'pause')->count(),
            'last_activity_at' => $items->pluck('last_attempt_at')->filter()->sortDesc()->first(),
        ];
    }

    protected function configPayload(string $portal): array
    {
        if ($portal !== 'ciencuadras') {
            return [
                'auto_sync' => false,
                'schedule' => 'Automático pendiente de configurar para este portal.',
            ];
        }

        return [
            'auto_sync' => (bool) config('portals.ciencuadras.auto_sync'),
            'limit' => (int) config('portals.ciencuadras.auto_sync_limit', 20),
            'scan' => (int) config('portals.ciencuadras.auto_sync_scan', 500),
            'schedule' => 'Auto-sync cada 5 minutos; verificación de pendientes cada minuto.',
        ];
    }
}
