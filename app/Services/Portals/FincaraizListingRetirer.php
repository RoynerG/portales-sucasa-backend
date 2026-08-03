<?php

namespace App\Services\Portals;

use App\Models\Integration;
use App\Models\PropertySyncStatus;
use App\Services\WordPressPropertyRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FincaraizListingRetirer
{
    public function __construct(
        protected FincaraizClient $client,
        protected WordPressPropertyRepository $wordpress,
        protected ?FincaraizListingReconciler $reconciler = null
    ) {}

    public function preview(array $settings, array $exportedListings): array
    {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $activeCodes = $this->activeCodes();
        $rows = collect($exportedListings)
            ->map(fn (array $row) => [
                'code' => trim((string) ($row['code'] ?? '')),
                'fr_property_id' => trim((string) ($row['fr_property_id'] ?? '')),
            ])
            ->filter(fn (array $row) => $row['code'] !== '' && $row['fr_property_id'] !== '')
            ->unique(fn (array $row) => $row['code'].'|'.$row['fr_property_id'])
            ->values();

        $remote = $this->activeRemoteListings($apiKey, $clientId);
        if (! $remote['ok']) {
            return [
                'ok' => false,
                'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
                'message' => $remote['message'],
                'items' => [],
            ];
        }

        $byPropertyId = collect($remote['listings'])->groupBy(
            fn (array $listing) => trim((string) ($listing['frPropertyId'] ?? ''))
        );
        $items = $rows->map(function (array $row) use ($activeCodes, $byPropertyId) {
            $isPublic = $activeCodes->contains($row['code']);
            $matches = $byPropertyId->get($row['fr_property_id'], collect());
            if ($matches->isEmpty()) {
                return $row + [
                    'state' => $isPublic ? 'protected_unlinked' : 'not_active',
                    'listing_ids' => [],
                    'message' => $isPublic
                        ? 'Sigue público localmente, pero no se encontró activo en Fincaraíz para enlazarlo.'
                        : 'No se encontró activo actualmente en Fincaraíz.',
                ];
            }
            if ($matches->count() !== 1) {
                $rawListingIds = $matches
                    ->pluck('id')
                    ->map(fn ($id) => trim((string) $id));
                $allListingIdsAreValid = $rawListingIds
                    ->every(fn (string $id) => Str::isUuid($id));
                $listingIds = $rawListingIds
                    ->unique()
                    ->values();

                return $row + [
                    'state' => $isPublic ? 'protected_unlinked' : 'ambiguous',
                    'matches' => $matches->count(),
                    'listing_ids' => $allListingIdsAreValid ? $listingIds->all() : [],
                    'message' => $isPublic
                        ? 'Sigue público localmente y tiene varias coincidencias activas. Puede retirarlas manualmente desde esta vista previa.'
                        : 'El código de Fincaraíz devolvió más de un aviso activo. Puede retirarlos manualmente desde esta vista previa.',
                ];
            }

            $listingId = trim((string) ($matches->first()['id'] ?? ''));
            if (! Str::isUuid($listingId)) {
                return $row + [
                    'state' => $isPublic ? 'protected_unlinked' : 'invalid_listing_id',
                    'listing_ids' => [],
                    'message' => 'Fincaraíz no devolvió un listing_id UUID válido.',
                ];
            }

            return $row + [
                'state' => $isPublic ? 'ready_to_link' : 'ready',
                'listing_id' => $listingId,
                'message' => $isPublic
                    ? 'Sigue público y su referencia local está lista para guardar.'
                    : 'Listo para desactivar.',
            ];
        })->values();
        $reviewItems = $items->whereNotIn('state', ['ready', 'ready_to_link']);
        $referencedListingIds = $this->referencedListingIds();
        $rowsByPropertyId = $rows->keyBy('fr_property_id');
        $unreferencedItems = collect($remote['listings'])
            ->map(function (array $listing) use ($rowsByPropertyId) {
                $listingId = trim((string) ($listing['id'] ?? ''));
                $propertyId = trim((string) ($listing['frPropertyId'] ?? ''));
                $row = $rowsByPropertyId->get($propertyId);

                return [
                    'code' => trim((string) ($row['code'] ?? '')),
                    'fr_property_id' => $propertyId,
                    'listing_id' => $listingId,
                    'state' => 'unreferenced_remote',
                    'message' => 'Aviso activo sin referencia local; puede retirarse para liberar cupo.',
                ];
            })
            ->filter(fn (array $item) => $item['code'] !== ''
                && $item['fr_property_id'] !== ''
                && Str::isUuid($item['listing_id'])
                && ! $referencedListingIds->contains($item['listing_id']))
            ->unique('listing_id')
            ->values();
        $removableListingIds = $unreferencedItems->pluck('listing_id')->unique()->values();

        return [
            'ok' => true,
            'dry_run' => true,
            'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
            'received' => count($exportedListings),
            'valid_rows' => $rows->count(),
            'active_remote' => count($remote['listings']),
            'ready' => $items->where('state', 'ready')->count(),
            'linkable' => $items->where('state', 'ready_to_link')->count(),
            'protected' => $items->whereIn('state', ['ready_to_link', 'protected_unlinked'])->count(),
            'review' => $reviewItems->count(),
            'removable_codes' => $unreferencedItems->pluck('code')->unique()->count(),
            'removable_listings' => $removableListingIds->count(),
            'unreferenced_items' => $unreferencedItems->all(),
            'items' => $items->all(),
        ];
    }

    public function apply(array $settings, array $previewItems): array
    {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $activeCodes = $this->activeCodes();
        $items = collect($previewItems)
            ->filter(fn ($item) => is_array($item)
                && in_array(($item['state'] ?? null), ['ready', 'ready_to_link'], true)
                && trim((string) ($item['code'] ?? '')) !== ''
                && trim((string) ($item['fr_property_id'] ?? '')) !== ''
                && Str::isUuid(trim((string) ($item['listing_id'] ?? ''))))
            ->unique('listing_id')
            ->values();

        $linkable = $items->filter(fn (array $item) => $activeCodes->contains(trim((string) $item['code'])))->values();
        $ready = $items->filter(fn (array $item) => ! $activeCodes->contains(trim((string) $item['code']))
            && ($item['state'] ?? null) === 'ready')->values();
        $catalogChanged = $items->filter(fn (array $item) => ! $activeCodes->contains(trim((string) $item['code']))
            && ($item['state'] ?? null) === 'ready_to_link')->values();
        $responses = $this->client->changeStatusesMany(
            $ready->pluck('listing_id')->all(),
            'DISABLED',
            $clientId,
            $apiKey,
            (int) config('portals.fincaraiz.retire_concurrency', 4)
        );
        $reconciler = $this->reconciler ?? app(FincaraizListingReconciler::class);
        $linkResult = $reconciler->applyPreview($linkable->map(
            fn (array $item) => array_replace($item, ['state' => 'ready'])
        )->all());
        $linkedByCode = collect($linkResult['items'] ?? [])->keyBy('code');

        $results = $linkable->map(function (array $item) use ($linkedByCode) {
            $linked = $linkedByCode->get(trim((string) $item['code']));

            return array_replace($item, is_array($linked) ? $linked : [
                'state' => 'local_error',
                'message' => 'No fue posible guardar la referencia local.',
            ]);
        })->concat($catalogChanged->map(fn (array $item) => array_replace($item, [
            'state' => 'catalog_changed',
            'message' => 'Ya no está público localmente; se omitió porque cambió después de la vista previa.',
        ])))->concat($ready->map(function (array $item) use ($responses) {
            $result = $responses[$item['listing_id']] ?? ['ok' => false, 'data' => []];
            $ok = (bool) ($result['ok'] ?? false);

            return array_replace($item, [
                'state' => $ok ? 'queued' : 'api_error',
                'task_id' => data_get($result, 'data.task.id'),
                'message' => $ok
                    ? 'Desactivación enviada a Fincaraíz.'
                    : (data_get($result, 'data.detail') ?: data_get($result, 'data.error') ?: 'Fincaraíz rechazó la desactivación.'),
            ]);
        }))->values();

        Log::notice('Desactivación masiva de avisos Fincaraíz', [
            'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
            'requested' => $items->count(),
            'queued' => $results->where('state', 'queued')->count(),
            'linked' => $results->where('state', 'linked')->count(),
            'protected' => $linkable->count(),
            'errors' => $results->whereIn('state', ['api_error', 'local_error'])->count(),
            'listing_ids' => $results->where('state', 'queued')->pluck('listing_id')->all(),
        ]);

        return [
            'ok' => $results->whereIn('state', ['api_error', 'local_error'])->isEmpty(),
            'dry_run' => false,
            'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
            'requested' => $items->count(),
            'queued' => $results->where('state', 'queued')->count(),
            'linked' => $results->where('state', 'linked')->count(),
            'protected' => $linkable->count(),
            'catalog_changed' => $results->where('state', 'catalog_changed')->count(),
            'errors' => $results->whereIn('state', ['api_error', 'local_error'])->count(),
            'items' => $results->all(),
        ];
    }

    public function applyUnresolved(array $settings, array $previewItems): array
    {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $items = collect($previewItems)
            ->filter(fn ($item) => is_array($item)
                && in_array(($item['state'] ?? null), ['ambiguous', 'protected_unlinked', 'unreferenced_remote'], true)
                && trim((string) ($item['code'] ?? '')) !== ''
                && trim((string) ($item['fr_property_id'] ?? '')) !== ''
                && (Str::isUuid(trim((string) ($item['listing_id'] ?? '')))
                    || (is_array($item['listing_ids'] ?? null) && ! empty($item['listing_ids']))))
            ->values();
        $targets = $items->flatMap(function (array $item) {
            $listingIds = Str::isUuid(trim((string) ($item['listing_id'] ?? '')))
                ? [$item['listing_id']]
                : ($item['listing_ids'] ?? []);

            return collect($listingIds)
                ->map(fn ($listingId) => trim((string) $listingId))
                ->filter(fn (string $listingId) => Str::isUuid($listingId))
                ->map(fn (string $listingId) => [
                    'code' => trim((string) $item['code']),
                    'fr_property_id' => trim((string) $item['fr_property_id']),
                    'listing_id' => $listingId,
                ]);
        })->unique('listing_id')->values();
        $responses = $this->client->changeStatusesMany(
            $targets->pluck('listing_id')->all(),
            'DISABLED',
            $clientId,
            $apiKey,
            (int) config('portals.fincaraiz.retire_concurrency', 4)
        );
        $results = $targets->map(function (array $item) use ($responses) {
            $result = $responses[$item['listing_id']] ?? ['ok' => false, 'data' => []];
            $ok = (bool) ($result['ok'] ?? false);

            return $item + [
                'state' => $ok ? 'queued' : 'api_error',
                'task_id' => data_get($result, 'data.task.id'),
                'message' => $ok
                    ? 'Desactivación manual enviada a Fincaraíz.'
                    : (data_get($result, 'data.detail') ?: data_get($result, 'data.error') ?: 'Fincaraíz rechazó la desactivación.'),
            ];
        })->values();

        Log::notice('Desactivación manual de avisos Fincaraíz sin enlace único', [
            'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
            'requested_codes' => $items->count(),
            'requested_listings' => $targets->count(),
            'queued' => $results->where('state', 'queued')->count(),
            'errors' => $results->where('state', 'api_error')->count(),
            'listing_ids' => $results->where('state', 'queued')->pluck('listing_id')->all(),
        ]);

        return [
            'ok' => $results->where('state', 'api_error')->isEmpty(),
            'dry_run' => false,
            'mode' => 'unresolved',
            'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
            'requested_codes' => $items->count(),
            'requested_listings' => $targets->count(),
            'queued' => $results->where('state', 'queued')->count(),
            'errors' => $results->where('state', 'api_error')->count(),
            'items' => $results->all(),
        ];
    }

    protected function activeCodes()
    {
        return $this->wordpress->activeCodes()
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
    }

    protected function referencedListingIds()
    {
        if (! Schema::hasTable('integrations') || ! Schema::hasTable('property_sync_statuses')) {
            return collect();
        }

        $integrationId = Integration::query()->where('slug', 'fincaraiz')->value('id');
        if (! $integrationId) {
            return collect();
        }

        return PropertySyncStatus::query()
            ->where('integration_id', $integrationId)
            ->where('environment', config('portals.fincaraiz.environment'))
            ->where('portal_variant', 'default')
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->map(fn ($listingId) => trim((string) $listingId))
            ->filter(fn (string $listingId) => Str::isUuid($listingId))
            ->unique()
            ->values();
    }

    protected function activeRemoteListings(string $apiKey, string $clientId): array
    {
        $active = [];
        for ($page = 1; $page <= 100; $page++) {
            $response = $this->client->listListings($apiKey, $clientId, $page, 100, null, '-created');
            if (! ($response['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => data_get($response, 'data.detail')
                        ?: data_get($response, 'data.error')
                        ?: 'No fue posible consultar todos los avisos de Fincaraíz.',
                ];
            }

            $results = collect(data_get($response, 'data.results', []))
                ->filter(fn ($listing) => is_array($listing) && (int) ($listing['status'] ?? -1) === 4)
                ->values()
                ->all();
            array_push($active, ...$results);

            $allResults = data_get($response, 'data.results', []);
            $next = data_get($response, 'data.next');
            if (! is_array($allResults) || count($allResults) < 100 || empty($next)) {
                break;
            }
        }

        return ['ok' => true, 'listings' => $active];
    }
}
