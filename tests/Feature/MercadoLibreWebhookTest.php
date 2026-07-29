<?php

namespace Tests\Feature;

use App\Http\Controllers\Portal\MercadoLibreController;
use App\Jobs\ProcessMercadoLibreNotification;
use App\Models\Integration;
use App\Models\PortalCredential;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MercadoLibreWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portals.mercadolibre.account_key', 'sucasa-shared');
        config()->set('portals.mercadolibre.client_id', '123456');
        config()->set('portals.mercadolibre.webhook_queue', 'mercadolibre');
        $this->createTables();

        $integration = Integration::create(['name' => 'Mercado Libre', 'slug' => 'mercadolibre']);
        PortalCredential::create([
            'user_id' => 1,
            'integration_id' => $integration->id,
            'account_key' => 'sucasa-shared',
            'access_token' => 'encrypted-by-cast',
            'refresh_token' => 'encrypted-by-cast',
            'access_token_expires_at' => now()->addHour(),
            'data' => ['external_user_id' => 987],
        ]);
    }

    public function test_duplicate_item_notifications_are_only_queued_once(): void
    {
        Queue::fake();
        $controller = app(MercadoLibreController::class);
        $payload = [
            '_id' => 'notification-1',
            'topic' => 'items',
            'resource' => '/items/MCO123456789',
            'application_id' => 123456,
            'user_id' => 987,
        ];

        $first = $controller->webhook(Request::create('/webhook', 'POST', $payload));
        $second = $controller->webhook(Request::create('/webhook', 'POST', $payload));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertDatabaseCount('mercadolibre_notifications', 1);
        Queue::assertPushed(ProcessMercadoLibreNotification::class, 1);
    }

    public function test_notification_with_another_application_is_ignored(): void
    {
        Queue::fake();
        $controller = app(MercadoLibreController::class);

        $response = $controller->webhook(Request::create('/webhook', 'POST', [
            '_id' => 'notification-invalid',
            'topic' => 'items',
            'resource' => '/items/MCO123456789',
            'application_id' => 999999,
            'user_id' => 987,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseCount('mercadolibre_notifications', 0);
        Queue::assertNothingPushed();
    }

    private function createTables(): void
    {
        Schema::dropIfExists('mercadolibre_notifications');
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
        Schema::create('mercadolibre_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('notification_id')->unique();
            $table->string('topic');
            $table->string('resource');
            $table->unsignedBigInteger('external_user_id')->nullable();
            $table->string('application_id')->nullable();
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }
}
