<?php

namespace App\Services\Portals;

use App\Models\PortalCredential;
use App\Models\Integration;
use App\Models\Property;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MercadoLibreClient
{
    public function __construct(protected Client $http) {}

    public function authorizeUrl(int $userId, string $clientId): string
    {
        $state = bin2hex(random_bytes(16));
        Cache::put("ml_oauth_state:{$state}", $userId, now()->addMinutes(10));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => config('portals.mercadolibre.redirect_uri'),
            'state'         => $state,
        ]);

        return config('portals.mercadolibre.auth_url') . '/authorization?' . $query;
    }

    public function exchangeCode(string $code, string $state): PortalCredential
    {
        $userId = Cache::pull("ml_oauth_state:{$state}");
        abort_unless($userId, 400, 'Invalid or expired OAuth state.');

        $response = $this->http->post(config('portals.mercadolibre.api_url') . '/oauth/token', [
            'form_params' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => config('portals.mercadolibre.client_id'),
                'client_secret' => config('portals.mercadolibre.client_secret'),
                'code'          => $code,
                'redirect_uri'  => config('portals.mercadolibre.redirect_uri'),
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        $integration = Integration::where('slug', 'mercadolibre')->firstOrFail();

        return PortalCredential::updateOrCreate(
            ['user_id' => $userId, 'integration_id' => $integration->id],
            [
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'access_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 21600),
                'data' => $data,
            ]
        );
    }

    public function getItem(string $itemId, PortalCredential $cred): array
    {
        return $this->request('GET', "/items/{$itemId}", $cred);
    }

    public function createItem(array $payload, PortalCredential $cred): array
    {
        return $this->request('POST', '/items', $cred, $payload);
    }

    public function updateItem(string $itemId, array $payload, PortalCredential $cred): array
    {
        return $this->request('PUT', "/items/{$itemId}", $cred, $payload);
    }

    public function changeStatus(string $itemId, string $status, PortalCredential $cred): array
    {
        return $this->request('PUT', "/items/{$itemId}", $cred, ['status' => $status]);
    }

    public function buildPayload(Property $property): array
    {
        $property->loadMissing(['propertyType', 'transactionType', 'neighborhood', 'images']);

        return [
            'title' => $property->title,
            'category_id' => $this->homologateType($property->propertyType?->slug),
            'price' => (float) ($property->display_price ?? 0),
            'currency_id' => $property->currency ?? 'COP',
            'available_quantity' => 1,
            'buying_mode' => 'classified',
            'listing_type_id' => 'gold',
            'condition' => $property->condition ?? 'used',
            'description' => [
                'plain_text' => $property->description ?? '',
            ],
            'attributes' => $this->buildAttributes($property),
            'location' => [
                'address_line' => $property->address ?? '',
                'neighborhood' => ['name' => $property->neighborhood?->name],
            ],
            'pictures' => $property->images
                ->map(fn ($image) => ['source' => $image->url])
                ->values()
                ->all(),
        ];
    }

    protected function buildAttributes(Property $property): array
    {
        $attrs = [];
        if ($property->area_built) {
            $attrs[] = ['id' => 'COVERED_AREA', 'value_name' => (string) $property->area_built];
        }
        if ($property->bedrooms) {
            $attrs[] = ['id' => 'BEDROOMS', 'value_name' => (string) $property->bedrooms];
        }
        if ($property->bathrooms) {
            $attrs[] = ['id' => 'FULL_BATHROOMS', 'value_name' => (string) $property->bathrooms];
        }
        if ($property->parking_spaces) {
            $attrs[] = ['id' => 'PARKING_LOTS', 'value_name' => (string) $property->parking_spaces];
        }
        return $attrs;
    }

    protected function homologateType(?string $type): string
    {
        $map = [
            'apartamento'  => 'MCO1473',
            'casa'         => 'MCO1468',
            'apartaestudio' => 'MCO1473',
            'oficina'      => 'MCO506',
            'local'        => 'MCO506',
            'lote'         => 'MCO1494',
            'finca'        => 'MCO1493',
            'bodega'       => 'MCO506',
            'edificio'     => 'MCO1473',
        ];
        return $map[strtolower($type ?? '')] ?? 'MCO1473';
    }

    protected function request(string $method, string $path, PortalCredential $cred, ?array $body = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $cred->access_token,
                'Accept' => 'application/json',
            ],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, config('portals.mercadolibre.api_url') . $path, $options);
            return [
                'ok' => true,
                'status' => $response->getStatusCode(),
                'data' => json_decode((string) $response->getBody(), true),
            ];
        } catch (GuzzleException $e) {
            Log::warning('ML request failed', ['path' => $path, 'err' => $e->getMessage()]);
            return [
                'ok' => false,
                'status' => $e->getCode() ?: 500,
                'data' => json_decode($e->getMessage(), true) ?? ['error' => $e->getMessage()],
            ];
        }
    }
}
