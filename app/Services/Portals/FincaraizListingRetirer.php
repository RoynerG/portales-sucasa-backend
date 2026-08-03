<?php

namespace App\Services\Portals;

use App\Services\WordPressPropertyRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FincaraizListingRetirer
{
    public function __construct(
        protected FincaraizClient $client,
        protected WordPressPropertyRepository $wordpress
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
            if ($activeCodes->contains($row['code'])) {
                return $row + [
                    'state' => 'protected_public',
                    'message' => 'Sigue público en el catálogo local.',
                ];
            }

            $matches = $byPropertyId->get($row['fr_property_id'], collect());
            if ($matches->isEmpty()) {
                return $row + [
                    'state' => 'not_active',
                    'message' => 'No se encontró activo actualmente en Fincaraíz.',
                ];
            }
            if ($matches->count() !== 1) {
                return $row + [
                    'state' => 'ambiguous',
                    'matches' => $matches->count(),
                    'message' => 'El código de Fincaraíz devolvió más de un aviso activo.',
                ];
            }

            $listingId = trim((string) ($matches->first()['id'] ?? ''));
            if (! Str::isUuid($listingId)) {
                return $row + [
                    'state' => 'invalid_listing_id',
                    'message' => 'Fincaraíz no devolvió un listing_id UUID válido.',
                ];
            }

            return $row + [
                'state' => 'ready',
                'listing_id' => $listingId,
                'message' => 'Listo para desactivar.',
            ];
        })->values();

        return [
            'ok' => true,
            'dry_run' => true,
            'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
            'received' => count($exportedListings),
            'valid_rows' => $rows->count(),
            'active_remote' => count($remote['listings']),
            'ready' => $items->where('state', 'ready')->count(),
            'protected' => $items->where('state', 'protected_public')->count(),
            'review' => $items->whereNotIn('state', ['ready', 'protected_public'])->count(),
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
                && ($item['state'] ?? null) === 'ready'
                && trim((string) ($item['code'] ?? '')) !== ''
                && trim((string) ($item['fr_property_id'] ?? '')) !== ''
                && Str::isUuid(trim((string) ($item['listing_id'] ?? ''))))
            ->unique('listing_id')
            ->values();

        $protected = $items->filter(fn (array $item) => $activeCodes->contains(trim((string) $item['code'])));
        $ready = $items->reject(fn (array $item) => $activeCodes->contains(trim((string) $item['code'])))->values();
        $responses = $this->client->changeStatusesMany(
            $ready->pluck('listing_id')->all(),
            'DISABLED',
            $clientId,
            $apiKey,
            (int) config('portals.fincaraiz.retire_concurrency', 4)
        );

        $results = $protected->map(fn (array $item) => array_replace($item, [
            'state' => 'protected_public',
            'message' => 'Se protegió porque volvió a estar público en el catálogo local.',
        ]))->concat($ready->map(function (array $item) use ($responses) {
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
            'protected' => $results->where('state', 'protected_public')->count(),
            'errors' => $results->where('state', 'api_error')->count(),
            'listing_ids' => $results->where('state', 'queued')->pluck('listing_id')->all(),
        ]);

        return [
            'ok' => $results->where('state', 'api_error')->isEmpty(),
            'dry_run' => false,
            'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
            'requested' => $items->count(),
            'queued' => $results->where('state', 'queued')->count(),
            'protected' => $results->where('state', 'protected_public')->count(),
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
