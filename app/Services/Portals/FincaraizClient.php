<?php

namespace App\Services\Portals;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FincaraizClient
{
    public function __construct(protected Client $http) {}

    public function getClientInfo(string $apiKey): array
    {
        return $this->getClients($apiKey);
    }

    public function getClients(string $apiKey): array
    {
        return $this->request('GET', '/client', $apiKey);
    }

    public function getClient(string $clientId, string $apiKey): array
    {
        return $this->request('GET', '/client/'.rawurlencode($clientId), $apiKey);
    }

    public function getAgents(string $clientId, string $apiKey): array
    {
        return $this->request('GET', '/client/'.rawurlencode($clientId).'/agent', $apiKey);
    }

    public function findLocations(string $name, string $apiKey): array
    {
        return $this->request('GET', '/location/'.rawurlencode($name), $apiKey);
    }

    public function listListings(
        string $apiKey,
        string $clientId,
        int $page = 1,
        int $pageSize = 20,
        ?string $search = null,
        string $ordering = '-created'
    ): array {
        return $this->request('GET', '/listing', $apiKey, null, array_filter([
            'page' => max(1, $page),
            'page_size' => min(100, max(1, $pageSize)),
            'search' => $search,
            'ordering' => $ordering,
        ], fn ($value) => $value !== null && $value !== ''), [
            'Cookie' => $clientId,
        ]);
    }

    public function getListing(string $apiKey, string $listingId): array
    {
        return $this->request('GET', '/listing/'.rawurlencode($listingId), $apiKey);
    }

    public function createListing(array $payload, string $apiKey): array
    {
        return $this->request('POST', '/listing', $apiKey, $this->batch($payload));
    }

    public function updateListing(string $listingId, array $payload, string $apiKey): array
    {
        $items = $this->batch($payload);
        $items = array_map(
            fn (array $item) => ['listing_id' => $listingId] + $item,
            $items
        );

        return $this->request('PATCH', '/listing', $apiKey, $items);
    }

    public function changeStatus(string $listingId, string $status, string $clientId, string $apiKey): array
    {
        return $this->request('PATCH', '/listing/status', $apiKey, [[
            'listing_id' => $listingId,
            'client_id' => $clientId,
            'status' => strtoupper($status),
        ]]);
    }

    public function validateListings(string $clientId, array $identifiers, string $apiKey): array
    {
        return $this->request('POST', '/validate-listing', $apiKey, ['client_id' => $clientId] + $identifiers);
    }

    public function getTask(string $taskId, string $apiKey): array
    {
        return $this->request('GET', '/task/'.rawurlencode($taskId), $apiKey);
    }

    public function subscribeWebhook(string $webhookId, string $target, string $apiKey, ?string $clientId = null): array
    {
        return $this->request(
            'POST',
            '/webhook/'.rawurlencode($webhookId).'/subscribe',
            $apiKey,
            array_filter(['target' => $target, 'client_id' => $clientId])
        );
    }

    public function unsubscribeWebhook(string $webhookId, string $apiKey): array
    {
        return $this->request('POST', '/webhook/'.rawurlencode($webhookId).'/unsubscribe', $apiKey);
    }

    protected function request(
        string $method,
        string $path,
        string $apiKey,
        ?array $body = null,
        array $query = [],
        array $headers = []
    ): array {
        if (strtoupper($method) === 'GET') {
            $query[$this->cacheBusterName()] = (string) Str::uuid();
        }

        $options = [
            'query' => $query,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'apikey' => $apiKey,
                ...$headers,
            ],
            'timeout' => (float) config('portals.fincaraiz.timeout', 30),
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request(
                $method,
                rtrim((string) config('portals.fincaraiz.api_url'), '/').$path,
                $options
            );
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $raw];

            return [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'data' => $data,
            ];
        } catch (GuzzleException $e) {
            Log::warning('Fincaraiz request failed', [
                'environment' => config('portals.fincaraiz.environment'),
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => method_exists($e, 'getResponse') && $e->getResponse()
                    ? $e->getResponse()->getStatusCode()
                    : 502,
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    protected function batch(array $payload): array
    {
        return array_is_list($payload) ? $payload : [$payload];
    }

    protected function cacheBusterName(): string
    {
        $name = trim((string) config('portals.fincaraiz.cache_buster_name', 'sucasa-cache'));

        return $name !== '' ? $name : 'sucasa-cache';
    }
}
