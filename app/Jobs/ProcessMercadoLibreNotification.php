<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\MercadoLibreNotification;
use App\Models\PortalCredential;
use App\Models\PropertySyncStatus;
use App\Services\Portals\MercadoLibreClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessMercadoLibreNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300];

    public function __construct(public int $notificationId) {}

    public function handle(MercadoLibreClient $client): void
    {
        $notification = MercadoLibreNotification::findOrFail($this->notificationId);
        if ($notification->processed_at) {
            return;
        }

        $itemId = basename($notification->resource);
        $integration = Integration::where('slug', 'mercadolibre')->firstOrFail();
        $credential = PortalCredential::where([
            'integration_id' => $integration->id,
            'account_key' => config('portals.mercadolibre.account_key'),
        ])->firstOrFail();
        $result = $client->getItem($itemId, $credential);
        if (! $result['ok']) {
            $notification->update([
                'status' => 'retrying',
                'error' => $client->errorMessage($result),
            ]);
            throw new RuntimeException($client->errorMessage($result));
        }

        $sync = PropertySyncStatus::where([
            'integration_id' => $integration->id,
            'external_id' => $itemId,
        ])->first();
        if ($sync) {
            $portalStatus = $result['data']['status'] ?? null;
            $sync->update([
                'sync_status' => match ($portalStatus) {
                    'active' => 'synced',
                    'paused' => 'paused',
                    'closed' => 'closed',
                    default => 'error',
                },
                'external_url' => $portalStatus === 'closed'
                    ? null
                    : ($result['data']['permalink'] ?? $sync->external_url),
                'last_response' => $result,
                'last_error' => null,
                'last_attempt_at' => now(),
                'last_synced_at' => $portalStatus === 'active' ? now() : $sync->last_synced_at,
            ]);
        }

        $notification->update([
            'status' => $sync ? 'processed' : 'ignored',
            'error' => null,
            'processed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        MercadoLibreNotification::whereKey($this->notificationId)->update([
            'status' => 'failed',
            'error' => $exception?->getMessage(),
        ]);
    }
}
