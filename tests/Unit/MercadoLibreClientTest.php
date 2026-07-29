<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\PortalCredential;
use App\Services\Portals\MercadoLibreClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MercadoLibreClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portals.mercadolibre.api_url', 'https://api.mercadolibre.com');
        config()->set('portals.mercadolibre.client_id', '123');
        config()->set('portals.mercadolibre.client_secret', 'secret');
        config()->set('portals.mercadolibre.auth_url', 'https://auth.mercadolibre.com.co');
        config()->set('portals.mercadolibre.redirect_uri', 'https://example.test/callback');
        config()->set('portals.mercadolibre.account_key', 'sucasa-shared');
    }

    public function test_validation_accepts_an_empty_204_response(): void
    {
        [$client] = $this->clientWith([new Response(204)]);

        $result = $client->raw('POST', '/items/validate', 'access-token', ['title' => 'Apartamento']);

        $this->assertTrue($result['ok']);
        $this->assertSame(204, $result['status']);
        $this->assertNull($result['data']);
    }

    public function test_validation_preserves_400_causes(): void
    {
        [$client] = $this->clientWith([
            new Response(400, [], json_encode([
                'message' => 'Validation error',
                'cause' => [
                    ['code' => 'item.attributes.missing', 'message' => 'Falta TOTAL_AREA'],
                ],
            ])),
        ]);

        $result = $client->raw('POST', '/items/validate', 'access-token', []);

        $this->assertFalse($result['ok']);
        $this->assertSame('item.attributes.missing', $result['error']['cause'][0]['code']);
        $this->assertStringContainsString('Falta TOTAL_AREA', $client->errorMessage($result));
    }

    public function test_rate_limit_exposes_retry_after(): void
    {
        [$client] = $this->clientWith([
            new Response(429, ['Retry-After' => '17'], '{"message":"Too many requests"}'),
        ]);

        $result = $client->raw('GET', '/users/me', 'access-token');

        $this->assertFalse($result['ok']);
        $this->assertSame(17, $result['retry_after']);
    }

    public function test_a_401_rotates_the_refresh_token_and_retries_once(): void
    {
        $this->createCredentialTables();
        $credential = $this->credential(expiresAt: now()->addHour());
        [$client, $pendingResponses] = $this->clientWith([
            new Response(401, [], '{"message":"expired"}'),
            new Response(200, [], '{"access_token":"new-access","refresh_token":"new-refresh","expires_in":21600}'),
            new Response(200, [], '{"id":"MCO123","status":"active"}'),
        ]);

        $result = $client->getItem('MCO123', $credential);

        $this->assertTrue($result['ok']);
        $this->assertCount(0, $pendingResponses);
        $this->assertSame('new-access', $credential->fresh()->access_token);
        $this->assertSame('new-refresh', $credential->fresh()->refresh_token);
    }

    public function test_a_stale_concurrent_credential_does_not_reuse_the_old_refresh_token(): void
    {
        $this->createCredentialTables();
        $first = $this->credential(expiresAt: now()->subMinute());
        $second = PortalCredential::findOrFail($first->id);
        [$client, $pendingResponses] = $this->clientWith([
            new Response(200, [], '{"access_token":"rotated-access","refresh_token":"rotated-refresh","expires_in":21600}'),
        ]);

        $freshFirst = $client->ensureFresh($first);
        $freshSecond = $client->ensureFresh($second);

        $this->assertCount(0, $pendingResponses);
        $this->assertSame('rotated-access', $freshFirst->access_token);
        $this->assertSame('rotated-access', $freshSecond->access_token);
        $this->assertSame('rotated-refresh', $freshSecond->refresh_token);
    }

    public function test_oauth_state_is_one_time_and_seller_metadata_is_saved(): void
    {
        $this->createCredentialTables();
        Integration::create(['name' => 'Mercado Libre', 'slug' => 'mercadolibre']);
        [$client, $pendingResponses] = $this->clientWith([
            new Response(200, [], '{"access_token":"oauth-access","refresh_token":"oauth-refresh","expires_in":21600,"user_id":987}'),
            new Response(200, [], '{"id":987,"nickname":"SUCASA_TEST","email":"ml@example.test","site_id":"MCO"}'),
        ]);

        parse_str(parse_url($client->authorizeUrl(77), PHP_URL_QUERY), $query);
        $this->assertSame(77, Cache::get('ml_oauth_state:'.$query['state']));

        $credential = $client->exchangeCode('authorization-code', $query['state']);

        $this->assertCount(0, $pendingResponses);
        $this->assertSame('sucasa-shared', $credential->account_key);
        $this->assertSame('oauth-access', $credential->access_token);
        $this->assertSame(987, $credential->data['external_user_id']);
        $this->assertFalse(Cache::has('ml_oauth_state:'.$query['state']));
    }

    public function test_invalid_grant_disconnects_the_shared_credential(): void
    {
        $this->createCredentialTables();
        $credential = $this->credential(expiresAt: now()->subMinute());
        [$client] = $this->clientWith([
            new Response(400, [], '{"error":"invalid_grant","error_description":"Refresh token already used"}'),
        ]);

        try {
            $client->ensureFresh($credential);
            $this->fail('The invalid refresh token should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Conecta nuevamente', $exception->getMessage());
        }

        $this->assertDatabaseMissing('portal_credentials', ['id' => $credential->id]);
    }

    private function clientWith(array $responses): array
    {
        $pendingResponses = new MockHandler($responses);
        $stack = HandlerStack::create($pendingResponses);
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        return [new TestableMercadoLibreClient($http), $pendingResponses];
    }

    private function createCredentialTables(): void
    {
        Schema::dropIfExists('portal_credentials');
        Schema::dropIfExists('integrations');

        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::create('portal_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('account_key')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    private function credential($expiresAt): PortalCredential
    {
        $integration = Integration::create(['name' => 'Mercado Libre', 'slug' => 'mercadolibre']);

        return PortalCredential::create([
            'user_id' => 1,
            'integration_id' => $integration->id,
            'account_key' => 'shared',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'access_token_expires_at' => $expiresAt,
            'data' => [],
        ]);
    }
}

class TestableMercadoLibreClient extends MercadoLibreClient
{
    public function raw(
        string $method,
        string $path,
        string $token,
        ?array $body = null,
        array $query = []
    ): array {
        return $this->requestWithToken($method, $path, $token, $body, $query);
    }
}
