<?php

namespace App\Services\Portals;

use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\WordPressPropertyRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class FincaraizListingReconciler
{
    public function __construct(
        protected FincaraizClient $client,
        protected FincaraizPropertyMapper $mapper,
        protected WordPressPropertyRepository $wordpress
    ) {}

    public function reconcile(array $settings, int $limit = 10, bool $dryRun = true): array
    {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $environment = (string) config('portals.fincaraiz.environment', 'qa');
        $integration = Integration::where('slug', 'fincaraiz')->firstOrFail();
        $limit = min(25, max(1, $limit));

        $codes = $this->candidateCodes($integration->id, $environment)->take($limit)->values();
        $items = [];
        $linked = 0;
        $matched = 0;
        $throttled = false;

        foreach ($codes as $code) {
            $response = $this->client->listListings($apiKey, $clientId, 1, 10, $code, '-created');
            if (! ($response['ok'] ?? false)) {
                $status = (int) ($response['status'] ?? 0);
                $items[] = [
                    'code' => $code,
                    'state' => $status === 429 ? 'throttled' : 'api_error',
                    'message' => data_get($response, 'data.detail')
                        ?: data_get($response, 'data.error')
                        ?: 'Fincaraíz rechazó la consulta.',
                ];
                if ($status === 429) {
                    $throttled = true;
                    break;
                }

                continue;
            }

            $active = collect(data_get($response, 'data.results', []))
                ->filter(fn ($listing) => is_array($listing) && (int) ($listing['status'] ?? -1) === 4)
                ->filter(fn (array $listing) => $this->matchesReturnedExternalCode($listing, $code))
                ->values();

            if ($active->isEmpty()) {
                $items[] = ['code' => $code, 'state' => 'not_found'];

                continue;
            }
            if ($active->count() !== 1) {
                $items[] = ['code' => $code, 'state' => 'ambiguous', 'matches' => $active->count()];

                continue;
            }

            $listing = $active->first();
            $listingId = trim((string) ($listing['id'] ?? ''));
            if (! Str::isUuid($listingId)) {
                $items[] = ['code' => $code, 'state' => 'invalid_listing_id'];

                continue;
            }

            $matched++;
            $item = [
                'code' => $code,
                'state' => $dryRun ? 'ready' : 'linked',
                'listing_id' => $listingId,
                'fr_property_id' => $listing['frPropertyId'] ?? null,
                'status' => 4,
            ];

            if (! $dryRun) {
                try {
                    $property = $this->mapper->ensureLocalProperty($code);
                    PropertySyncStatus::updateOrCreate(
                        [
                            'property_id' => $property->id,
                            'integration_id' => $integration->id,
                            'environment' => $environment,
                            'portal_variant' => 'default',
                        ],
                        [
                            'sync_status' => 'synced',
                            'external_id' => $listingId,
                            'last_response' => [
                                'action' => 'reconcile_active_listing',
                                'source' => 'GET /listing?search='.$code,
                                'fr_property_id' => $listing['frPropertyId'] ?? null,
                                'listing' => $listing,
                            ],
                            'last_error' => null,
                            'last_synced_at' => now(),
                            'last_attempt_at' => now(),
                        ]
                    );
                    $linked++;
                } catch (Throwable $exception) {
                    report($exception);
                    $item['state'] = 'local_error';
                    $item['message'] = $exception->getMessage();
                    unset($item['listing_id']);
                }
            }

            $items[] = $item;
        }

        return [
            'dry_run' => $dryRun,
            'environment' => $environment,
            'batch_limit' => $limit,
            'candidates' => $codes->count(),
            'processed' => count($items),
            'matched' => $matched,
            'linked' => $linked,
            'remaining' => max(0, $this->candidateCodes($integration->id, $environment)->count() - $linked),
            'throttled' => $throttled,
            'items' => $items,
        ];
    }

    protected function candidateCodes(int $integrationId, string $environment): Collection
    {
        $codes = $this->wordpress->enabled()
            ? $this->wordpress->activeCodes()
            : Property::where('status', 'active')->orderBy('code')->pluck('code');

        $alreadyLinked = Property::query()
            ->whereIn('code', $codes)
            ->whereHas('syncStatuses', fn ($query) => $query
                ->where('integration_id', $integrationId)
                ->where('environment', $environment)
                ->where('portal_variant', 'default')
                ->whereNotNull('external_id'))
            ->pluck('code');

        return $codes
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->diff($alreadyLinked)
            ->unique()
            ->sort()
            ->values();
    }

    protected function matchesReturnedExternalCode(array $listing, string $code): bool
    {
        $returned = $listing['external_code']
            ?? $listing['externalCode']
            ?? $listing['integrator_code']
            ?? $listing['integratorCode']
            ?? null;

        return $returned === null || trim((string) $returned) === $code;
    }
}
