<?php

namespace App\Services\Portals;

use App\Models\Integration;
use App\Models\PortalCredential;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MercadoLibreClient
{
    public function __construct(protected Client $http) {}

    public function authorizeUrl(int $userId): string
    {
        $state = bin2hex(random_bytes(32));
        Cache::put("ml_oauth_state:{$state}", $userId, now()->addMinutes(10));

        return config('portals.mercadolibre.auth_url').'/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('portals.mercadolibre.client_id'),
            'redirect_uri' => config('portals.mercadolibre.redirect_uri'),
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $state): PortalCredential
    {
        $userId = Cache::pull("ml_oauth_state:{$state}");
        abort_unless($userId, 400, 'El estado OAuth es inválido o expiró.');

        $token = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => config('portals.mercadolibre.client_id'),
            'client_secret' => config('portals.mercadolibre.client_secret'),
            'code' => $code,
            'redirect_uri' => config('portals.mercadolibre.redirect_uri'),
        ]);
        $me = $this->requestWithToken('GET', '/users/me', $token['access_token']);
        if (! $me['ok']) {
            throw new RuntimeException($this->errorMessage($me));
        }

        $integration = Integration::where('slug', 'mercadolibre')->firstOrFail();
        $metadata = [
            'token_type' => $token['token_type'] ?? 'bearer',
            'scope' => $token['scope'] ?? null,
            'external_user_id' => $token['user_id'] ?? ($me['data']['id'] ?? null),
            'nickname' => $me['data']['nickname'] ?? null,
            'email' => $me['data']['email'] ?? null,
            'site_id' => $me['data']['site_id'] ?? config('portals.mercadolibre.site_id'),
        ];

        return PortalCredential::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'account_key' => config('portals.mercadolibre.account_key'),
            ],
            [
                'user_id' => $userId,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 21600)),
                'data' => $metadata,
            ]
        );
    }

    public function getUser(PortalCredential $credential): array
    {
        return $this->request('GET', '/users/me', $credential);
    }

    public function packages(PortalCredential $credential): array
    {
        $userId = $credential->data['external_user_id'] ?? null;
        if (! $userId) {
            return $this->failure(422, ['message' => 'La credencial no contiene el usuario vendedor.']);
        }

        return $this->request(
            'GET',
            "/users/{$userId}/classifieds_promotion_packs",
            $credential,
            query: ['package_content' => 'ALL', 'status' => 'active']
        );
    }

    public function getCategory(string $categoryId, PortalCredential $credential): array
    {
        return $this->request('GET', "/categories/{$categoryId}", $credential);
    }

    public function siteCategories(PortalCredential $credential): array
    {
        return $this->request('GET', '/sites/'.config('portals.mercadolibre.site_id').'/categories', $credential);
    }

    public function categoryAttributes(string $categoryId, PortalCredential $credential): array
    {
        return $this->request('GET', "/categories/{$categoryId}/attributes", $credential);
    }

    public function country(PortalCredential $credential): array
    {
        return $this->request(
            'GET',
            '/classified_locations/countries/'.config('portals.mercadolibre.country_id'),
            $credential
        );
    }

    public function state(string $stateId, PortalCredential $credential): array
    {
        return $this->request('GET', "/classified_locations/states/{$stateId}", $credential);
    }

    public function city(string $cityId, PortalCredential $credential): array
    {
        return $this->request('GET', "/classified_locations/cities/{$cityId}", $credential);
    }

    public function validateItem(array $payload, PortalCredential $credential): array
    {
        return $this->request('POST', '/items/validate', $credential, $payload);
    }

    public function getItem(string $itemId, PortalCredential $credential): array
    {
        return $this->request('GET', "/items/{$itemId}", $credential);
    }

    public function createItem(array $payload, PortalCredential $credential): array
    {
        return $this->request('POST', '/items', $credential, $payload);
    }

    public function updateItem(string $itemId, array $payload, PortalCredential $credential): array
    {
        return $this->request('PUT', "/items/{$itemId}", $credential, $payload);
    }

    public function createDescription(string $itemId, string $description, PortalCredential $credential): array
    {
        return $this->request('POST', "/items/{$itemId}/description", $credential, ['plain_text' => $description]);
    }

    public function updateDescription(string $itemId, string $description, PortalCredential $credential): array
    {
        return $this->request(
            'PUT',
            "/items/{$itemId}/description",
            $credential,
            ['plain_text' => $description],
            ['api_version' => 2]
        );
    }

    public function changeStatus(string $itemId, string $status, PortalCredential $credential): array
    {
        return $this->request('PUT', "/items/{$itemId}", $credential, ['status' => $status]);
    }

    public function setAddressVisibility(string $itemId, bool $showExact, PortalCredential $credential): array
    {
        return $this->request(
            $showExact ? 'DELETE' : 'PUT',
            "/items/{$itemId}/address_line_by_reference",
            $credential
        );
    }

    public function ensureFresh(PortalCredential $credential): PortalCredential
    {
        if (! $credential->expiresSoon()) {
            return $credential;
        }

        return $this->refreshCredential($credential);
    }

    protected function request(
        string $method,
        string $path,
        PortalCredential $credential,
        ?array $body = null,
        array $query = []
    ): array {
        try {
            $credential = $this->ensureFresh($credential);
            $result = $this->requestWithToken($method, $path, $credential->access_token, $body, $query);

            if ($result['status'] === 401 && $credential->refresh_token) {
                $credential = $this->refreshCredential($credential, force: true);
                $result = $this->requestWithToken($method, $path, $credential->access_token, $body, $query);
            }

            return $result;
        } catch (Throwable $exception) {
            Log::warning('Mercado Libre request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return $this->failure(500, ['message' => $exception->getMessage()]);
        }
    }

    protected function requestWithToken(
        string $method,
        string $path,
        string $accessToken,
        ?array $body = null,
        array $query = []
    ): array {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }

        $response = $this->http->request(
            $method,
            config('portals.mercadolibre.api_url').$path,
            $options
        );
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $data = $raw === '' ? null : json_decode($raw, true);
        $result = [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $data,
        ];
        if (! $result['ok']) {
            $result['error'] = is_array($data) ? $data : ['message' => $raw ?: "HTTP {$status}"];
            if ($status === 429 && $response->hasHeader('Retry-After')) {
                $result['retry_after'] = (int) $response->getHeaderLine('Retry-After');
            }
        }

        return $result;
    }

    protected function refreshCredential(PortalCredential $credential, bool $force = false): PortalCredential
    {
        $disconnectMessage = null;
        $refreshed = DB::transaction(function () use ($credential, $force, &$disconnectMessage): ?PortalCredential {
            $locked = PortalCredential::query()->lockForUpdate()->findOrFail($credential->id);
            if ($force && $locked->access_token !== $credential->access_token) {
                return $locked;
            }
            if (! $force && ! $locked->expiresSoon()) {
                return $locked;
            }
            if (! $locked->refresh_token) {
                throw new RuntimeException('Mercado Libre requiere volver a autorizar la cuenta.');
            }

            try {
                $token = $this->tokenRequest([
                    'grant_type' => 'refresh_token',
                    'client_id' => config('portals.mercadolibre.client_id'),
                    'client_secret' => config('portals.mercadolibre.client_secret'),
                    'refresh_token' => $locked->refresh_token,
                ]);
            } catch (MercadoLibreTokenException $exception) {
                if (in_array($exception->oauthError, ['invalid_grant', 'invalid_token'], true)) {
                    $locked->delete();
                    $disconnectMessage = 'Mercado Libre invalidó la autorización. Conecta nuevamente la cuenta empresarial.';

                    return null;
                }

                throw $exception;
            }

            $locked->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $locked->refresh_token,
                'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 21600)),
                'data' => array_merge($locked->data ?? [], [
                    'token_type' => $token['token_type'] ?? 'bearer',
                    'scope' => $token['scope'] ?? ($locked->data['scope'] ?? null),
                ]),
            ]);

            return $locked->fresh();
        }, 3);

        if ($disconnectMessage) {
            throw new RuntimeException($disconnectMessage);
        }

        return $refreshed;
    }

    protected function tokenRequest(array $form): array
    {
        $response = $this->http->post(config('portals.mercadolibre.api_url').'/oauth/token', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => $form,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true) ?: [];

        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            throw new MercadoLibreTokenException(
                $data['error_description'] ?? $data['message'] ?? 'No fue posible obtener el token de Mercado Libre.',
                $status,
                (string) ($data['error'] ?? '')
            );
        }

        return $data;
    }

    protected function failure(int $status, array $error): array
    {
        return ['ok' => false, 'status' => $status, 'data' => $error, 'error' => $error];
    }

    public function errorMessage(array $result): string
    {
        $error = $result['error'] ?? $result['data'] ?? [];
        $messages = collect([
            $error['message'] ?? null,
            $error['error_description'] ?? null,
            ...collect($error['cause'] ?? [])->pluck('message')->all(),
        ])->filter()->unique()->values();

        return $messages->isNotEmpty()
            ? $messages->implode(' ')
            : 'Mercado Libre rechazó la solicitud.';
    }
}

class MercadoLibreTokenException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $httpStatus,
        public string $oauthError
    ) {
        parent::__construct($message, $httpStatus);
    }
}
