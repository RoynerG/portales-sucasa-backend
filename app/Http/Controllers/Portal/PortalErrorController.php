<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PropertySyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalErrorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $portal = $request->query('portal', 'ciencuadras');
        $statuses = collect(explode(',', (string) $request->query('statuses', 'all')))
            ->map(fn (string $status) => trim($status))
            ->filter()
            ->values()
            ->all();
        $allStatuses = in_array('all', $statuses, true);
        $limit = min(500, max(25, (int) $request->query('limit', 200)));

        $integrations = Integration::query()
            ->active()
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $statusCounts = PropertySyncStatus::query()
            ->select('integration_id', 'sync_status', DB::raw('COUNT(*) as total'))
            ->groupBy('integration_id', 'sync_status')
            ->get()
            ->groupBy('integration_id');

        $portalSummaries = $integrations->map(function (Integration $integration) use ($statusCounts) {
            $counts = $statusCounts->get($integration->id, collect())
                ->pluck('total', 'sync_status');

            return [
                'id' => $integration->id,
                'slug' => $integration->slug,
                'name' => $integration->name,
                'active' => $integration->active,
                'website_url' => $integration->website_url,
                'description' => $integration->description,
                'summary' => $this->summaryPayload($counts),
            ];
        })->values();

        $query = PropertySyncStatus::query()
            ->with(['property', 'integration'])
            ->whereHas('integration', fn ($query) => $query->where('slug', $portal));

        if (! $allStatuses) {
            $query->whereIn('sync_status', $statuses);
        }

        $items = $query
            ->orderByRaw('COALESCE(last_attempt_at, last_synced_at, updated_at) DESC')
            ->limit($limit)
            ->get()
            ->map(fn (PropertySyncStatus $status) => [
                'id' => $status->id,
                'portal' => $status->integration?->slug,
                'portal_name' => $status->integration?->name,
                'environment' => $status->environment,
                'sync_status' => $status->sync_status,
                'external_id' => $status->external_id,
                'external_url' => $status->external_url,
                'last_error' => $status->last_error,
                'last_response' => $status->last_response,
                'last_attempt_at' => $status->last_attempt_at?->toIso8601String(),
                'last_synced_at' => $status->last_synced_at?->toIso8601String(),
                'attempts' => $status->attempts,
                'property' => [
                    'code' => $status->property?->code,
                    'title' => $status->property?->title,
                    'status' => $status->property?->status,
                    'address' => $status->property?->address,
                ],
            ]);

        $selectedIntegration = $integrations->firstWhere('slug', $portal);
        $selectedCounts = $selectedIntegration
            ? $statusCounts->get($selectedIntegration->id, collect())->pluck('total', 'sync_status')
            : collect();

        return response()->json(['Datos' => [
            'portal' => $portal,
            'portals' => $portalSummaries,
            'items' => $items,
            'summary' => $this->summaryPayload($selectedCounts),
        ]]);
    }

    protected function summaryPayload($counts): array
    {
        $counts = collect($counts);

        return [
            'total' => (int) $counts->sum(),
            'synced' => (int) ($counts->get('synced', 0) ?: 0),
            'pending' => (int) ($counts->get('pending', 0) ?: 0),
            'syncing' => (int) ($counts->get('syncing', 0) ?: 0),
            'error' => (int) ($counts->get('error', 0) ?: 0),
            'paused' => (int) ($counts->get('paused', 0) ?: 0),
            'not_synced' => (int) ($counts->get('not_synced', 0) ?: 0),
        ];
    }
}
