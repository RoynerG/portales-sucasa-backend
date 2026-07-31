<?php

namespace Tests\Feature;

use App\Http\Controllers\Portal\FincaraizController;
use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\FincaraizPropertyMapper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FincaraizWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portals.fincaraiz.environment', 'qa');
        config()->set('portals.fincaraiz.webhook_id', 'hub-qa');
        config()->set('portals.fincaraiz.webhook_verify_token', 'verify-secret');
        $this->createTables();
    }

    public function test_webhook_confirms_listing_id_and_leaves_new_listing_ready_for_activation(): void
    {
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $property = Property::create(['code' => '53824', 'title' => 'Apartamento']);
        PropertySyncStatus::create([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => 'qa',
            'portal_variant' => 'default',
            'sync_status' => 'pending',
            'last_response' => ['action' => 'publish', 'task_id' => 'task-1'],
        ]);
        $request = Request::create('/api/portals/fincaraiz/webhook', 'POST', [], [], [], [], json_encode([
            'task' => [
                'id' => 'task-1',
                'status' => 'COMPLETED',
                'content' => [[
                    'status' => 'COMPLETED',
                    'external_code' => '53824',
                    'listing_id' => '07bcf513-d39a-42ff-8370-f42d39cd9494',
                    'fr_property_id' => 10002073,
                ]],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('HUB.ID', 'hub-qa');
        $request->headers->set('VERIFY-TOKEN', 'verify-secret');

        $controller = $this->controller();
        $response = $controller->webhook($request);
        $controller->webhook($request);

        $sync = PropertySyncStatus::first();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['Datos']['processed']);
        $this->assertSame('pending', $sync->sync_status);
        $this->assertSame('07bcf513-d39a-42ff-8370-f42d39cd9494', $sync->external_id);
        $this->assertSame('activate_required', $sync->last_response['action']);
        $this->assertTrue($sync->last_response['requires_activation']);
    }

    public function test_webhook_rejects_an_invalid_verify_token(): void
    {
        Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $request = Request::create('/api/portals/fincaraiz/webhook', 'POST');
        $request->headers->set('HUB.ID', 'hub-qa');
        $request->headers->set('VERIFY-TOKEN', 'wrong');

        $response = $this->controller()->webhook($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    private function controller(): FincaraizController
    {
        return new FincaraizController(
            new class extends FincaraizClient
            {
                public function __construct() {}
            },
            new FincaraizPropertyMapper
        );
    }

    private function createTables(): void
    {
        Schema::dropIfExists('property_sync_statuses');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('integrations');

        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('property_sync_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('environment')->default('production');
            $table->string('portal_variant')->default('default');
            $table->string('sync_status')->default('not_synced');
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
        });
    }
}
