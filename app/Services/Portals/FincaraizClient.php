<?php

namespace App\Services\Portals;

use App\Models\PortalCredential;
use App\Models\Property;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class FincaraizClient
{
    public function __construct(protected Client $http) {}

    public function getClientInfo(string $apiKey): array
    {
        return $this->request('GET', '/client', null, ['apikey' => $apiKey]);
    }

    public function listListings(string $apiKey, int $page = 1, int $pageSize = 20): array
    {
        return $this->request('GET', '/listing', null, [
            'apikey' => $apiKey,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function getListing(string $apiKey, string $listingId): array
    {
        return $this->request('GET', "/listing/{$listingId}", null, ['apikey' => $apiKey]);
    }

    public function createListing(array $payload, string $apiKey): array
    {
        return $this->request('POST', '/listing', $payload, ['apikey' => $apiKey]);
    }

    public function updateListing(string $listingId, array $payload, string $apiKey): array
    {
        return $this->request('PATCH', '/listing', ['listing_id' => $listingId] + $payload, ['apikey' => $apiKey]);
    }

    public function changeStatus(string $listingId, string $status, string $clientId, string $apiKey): array
    {
        return $this->request('PATCH', '/listing/status', [[
            'listing_id' => $listingId,
            'client_id' => $clientId,
            'status' => $status,
        ]], ['apikey' => $apiKey]);
    }

    public function getTask(string $taskId, string $apiKey): array
    {
        return $this->request('GET', "/task/{$taskId}", null, ['apikey' => $apiKey]);
    }

    public function buildPayload(Property $property): array
    {
        $property->loadMissing(['propertyType', 'transactionType', 'neighborhood', 'images']);

        return [
            'title' => $property->title,
            'description' => $property->description,
            'property_type_id' => $this->homologateType($property->propertyType?->slug),
            'transaction_type' => $this->homologateTransaction($property->transactionType?->slug),
            'price' => [
                'value' => (float) ($property->display_price ?? 0),
                'currency' => $property->currency ?? 'COP',
                'period' => str_contains($property->transactionType?->slug ?? '', 'rent') ? 'monthly' : 'total',
            ],
            'area' => [
                'total' => (float) ($property->area_total ?? $property->area_land ?? 0),
                'covered' => (float) ($property->area_built ?? 0),
                'private' => (float) ($property->area_private ?? 0),
                'unit' => 'm2',
            ],
            'rooms' => min(19, (int) ($property->bedrooms ?? 0)),
            'baths' => min(9, (int) ($property->bathrooms ?? 0)),
            'garages' => min(10, (int) ($property->parking_spaces ?? 0)),
            'floor' => min(16, (int) ($property->floor_number ?? 0)),
            'stratum' => $property->stratum,
            'age' => $property->age_years,
            'condition' => $property->condition ?? 'used',
            'address' => [
                'street' => $property->address,
                'neighborhood' => $property->neighborhood?->name,
                'city_id' => null,
                'lat' => (float) $property->lat,
                'lng' => (float) $property->lng,
            ],
            'images' => $property->images
                ->map(fn ($image) => ['url' => $image->url])
                ->values()
                ->all(),
            'contact' => [
                'name' => $property->contact_name,
                'email' => $property->contact_email,
                'phone' => $property->contact_phone,
                'whatsapp' => $property->contact_whatsapp,
            ],
        ];
    }

    protected function homologateType(?string $type): string
    {
        $map = [
            'apartamento'  => 'APT',
            'casa'         => 'HOUSE',
            'apartaestudio' => 'APT',
            'oficina'      => 'OFFICE',
            'local'        => 'COMMERCIAL',
            'lote'         => 'LOT',
            'finca'        => 'FARM',
            'bodega'       => 'WAREHOUSE',
            'edificio'     => 'BUILDING',
        ];
        return $map[strtolower($type ?? '')] ?? 'APT';
    }

    protected function homologateTransaction(?string $type): string
    {
        $t = strtolower($type ?? '');
        if (str_contains($t, 'rent') || str_contains($t, 'arriendo')) {
            return 'rent';
        }
        return 'sale';
    }

    protected function request(string $method, string $path, array|string|null $body, array $query = []): array
    {
        $options = [
            'query' => $query,
            'headers' => ['Accept' => 'application/json'],
        ];
        if (is_array($body)) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, config('portals.fincaraiz.api_url') . $path, $options);
            return [
                'ok' => true,
                'status' => $response->getStatusCode(),
                'data' => json_decode((string) $response->getBody(), true),
            ];
        } catch (GuzzleException $e) {
            Log::warning('FR request failed', ['path' => $path, 'err' => $e->getMessage()]);
            return [
                'ok' => false,
                'status' => $e->getCode() ?: 500,
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }
}
