<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PropertySyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalErrorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $portal = $request->query('portal', 'ciencuadras');
        $statuses = collect(explode(',', (string) $request->query('statuses', 'error,pending,syncing')))
            ->map(fn (string $status) => trim($status))
            ->filter()
            ->values()
            ->all();

        $items = PropertySyncStatus::query()
            ->with(['property', 'integration'])
            ->whereIn('sync_status', $statuses)
            ->whereHas('integration', fn ($query) => $query->where('slug', $portal))
            ->latest('last_attempt_at')
            ->limit(200)
            ->get()
            ->map(fn (PropertySyncStatus $status) => [
                'id' => $status->id,
                'portal' => $status->integration?->slug,
                'portal_name' => $status->integration?->name,
                'environment' => $status->environment,
                'sync_status' => $status->sync_status,
                'external_id' => $status->external_id,
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

        return response()->json(['Datos' => [
            'portal' => $portal,
            'items' => $items,
            'summary' => [
                'total' => $items->count(),
                'error' => $items->where('sync_status', 'error')->count(),
                'pending' => $items->where('sync_status', 'pending')->count(),
                'syncing' => $items->where('sync_status', 'syncing')->count(),
            ],
        ]]);
    }
}
