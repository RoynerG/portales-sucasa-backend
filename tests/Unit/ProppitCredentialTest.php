<?php

namespace Tests\Unit;

use App\Http\Controllers\Portal\ProppitController;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Services\Portals\ProppitClient;
use App\Services\Portals\ProppitPropertyMapper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProppitCredentialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('portal_credentials');
        Schema::dropIfExists('integrations');

        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
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

        config()->set('portals.proppit.user', 'client-id');
        config()->set('portals.proppit.password', 'client-secret');
        config()->set('portals.proppit.country', 'CO');
        config()->set('portals.proppit.publisher_external_id', 'sucasa');
        config()->set('portals.proppit.api_url', 'https://real-time.proppit.test/api/v2');
    }

    public function test_login_replaces_a_legacy_plaintext_token_instead_of_trying_to_decrypt_it(): void
    {
        $integration = Integration::create(['name' => 'Proppit', 'slug' => 'proppit']);
        $fingerprint = hash('sha256', "client-id\0client-secret");

        DB::table('portal_credentials')->insert([
            'user_id' => 1,
            'integration_id' => $integration->id,
            'access_token' => 'legacy-plaintext-token',
            'access_token_expires_at' => now()->addHour(),
            'data' => json_encode(['credential_fingerprint' => $fingerprint]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/portals/proppit/login', 'POST');
        $request->setUserResolver(fn () => (object) ['id' => 1]);
        $controller = new TestableProppitController(
            new FakeProppitClient,
            new ProppitPropertyMapper
        );

        $credential = $controller->credentialForTest($request);

        $this->assertSame('fresh-encrypted-token', $credential->access_token);
        $this->assertSame(1, PortalCredential::count());
        $this->assertNotSame(
            'fresh-encrypted-token',
            DB::table('portal_credentials')->value('access_token')
        );
    }

    public function test_login_creates_the_publisher_when_proppit_reports_it_missing(): void
    {
        Integration::create(['name' => 'Proppit', 'slug' => 'proppit']);
        config()->set('portals.proppit.default_contact_name', 'Su Casa Inmobiliaria');
        config()->set('portals.proppit.default_contact_email', 'contacto@sucasa.com');
        config()->set('portals.proppit.default_contact_phone', '3001234567');

        $request = Request::create('/api/portals/proppit/login', 'POST');
        $request->setUserResolver(fn () => (object) ['id' => 1]);
        $client = new FakeProppitClient;
        $controller = new TestableProppitController(
            $client,
            new ProppitPropertyMapper
        );

        $response = $controller->login($request);
        $data = $response->getData(true)['Datos'];

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['publisher']['created']);
        $this->assertFalse($data['publisher']['publishing_enabled']);
        $this->assertSame([
            'id' => 'sucasa',
            'name' => 'Su Casa Inmobiliaria',
            'email' => 'contacto@sucasa.com',
            'phone' => '3001234567',
        ], $client->createdPublisherPayload);
    }
}

class TestableProppitController extends ProppitController
{
    public function credentialForTest(Request $request): PortalCredential
    {
        return $this->credential($request);
    }
}

class FakeProppitClient extends ProppitClient
{
    public ?array $createdPublisherPayload = null;

    public function __construct() {}

    public function token(?array $credentials = null): array
    {
        return [
            'ok' => true,
            'data' => [
                'token' => 'fresh-encrypted-token',
                'expiration' => now()->addHour()->timestamp,
            ],
        ];
    }

    public function getPublisher(string $externalId, string $token): array
    {
        return [
            'ok' => false,
            'data' => [
                'status' => 404,
                'body' => [
                    'status' => 404,
                    'requestId' => 'request-123',
                    'error' => 'Publisher not found',
                ],
            ],
        ];
    }

    public function createPublisher(array $payload, string $token): array
    {
        $this->createdPublisherPayload = $payload;

        return [
            'ok' => true,
            'status' => 201,
            'data' => [
                'id' => $payload['id'],
                'name' => $payload['name'] ?? null,
                'email' => $payload['email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'publishingEnabled' => false,
            ],
        ];
    }
}
