<?php

namespace Tests\Unit;

use App\Models\Property;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\FincaraizListingReconciler;
use App\Services\Portals\FincaraizPropertyMapper;
use App\Services\WordPressPropertyRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FincaraizListingReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sources.properties', 'database');
        config()->set('portals.fincaraiz.environment', 'production');

        Schema::dropIfExists('property_sync_statuses');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('integrations');
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('property_sync_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('environment');
            $table->string('portal_variant')->default('default');
            $table->string('sync_status');
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['property_id', 'integration_id', 'environment', 'portal_variant'], 'property_sync_variant_unique');
        });

        DB::table('integrations')->insert(['id' => 1, 'slug' => 'fincaraiz']);
    }

    public function test_it_only_links_one_active_exact_search_result(): void
    {
        $property = Property::create(['code' => '53824', 'status' => 'active']);
        Property::create(['code' => '99999', 'status' => 'active']);
        $listingId = '7be7c83d-10b1-417b-a661-484ff5ebd821';

        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('listListingsMany')->willReturn([
            '53824' => [
                'ok' => true,
                'status' => 200,
                'data' => ['results' => [[
                    'id' => $listingId,
                    'frPropertyId' => '1511253',
                    'status' => 4,
                ]]],
            ],
            '99999' => [
                'ok' => true,
                'status' => 200,
                'data' => ['results' => [[
                    'id' => 'a59f7867-df68-4464-b7cc-eab36ee14ad7',
                    'status' => 1,
                ]]],
            ],
        ]);
        $mapper = $this->createMock(FincaraizPropertyMapper::class);
        $mapper->expects($this->once())->method('ensureLocalProperty')->with('53824')->willReturn($property);
        $wordpress = $this->createMock(WordPressPropertyRepository::class);
        $wordpress->method('enabled')->willReturn(false);

        $result = (new FincaraizListingReconciler($client, $mapper, $wordpress))->reconcile([
            'api_key' => 'production-key',
            'client_id' => 'df03d199-be5c-4c5c-98f6-849361cb7fae',
        ], 10, false);

        $this->assertSame(1, $result['linked']);
        $this->assertSame('not_found', $result['items'][1]['state']);
        $this->assertDatabaseHas('property_sync_statuses', [
            'property_id' => $property->id,
            'integration_id' => 1,
            'environment' => 'production',
            'sync_status' => 'synced',
            'external_id' => $listingId,
        ]);
    }

    public function test_dry_run_does_not_write_and_rejects_ambiguous_results(): void
    {
        Property::create(['code' => '53824', 'status' => 'active']);
        $client = $this->createMock(FincaraizClient::class);
        $client->method('listListingsMany')->willReturn([
            '53824' => [
                'ok' => true,
                'status' => 200,
                'data' => ['results' => [
                    ['id' => '7be7c83d-10b1-417b-a661-484ff5ebd821', 'status' => 4],
                    ['id' => 'a59f7867-df68-4464-b7cc-eab36ee14ad7', 'status' => 4],
                ]],
            ],
        ]);
        $mapper = $this->createMock(FincaraizPropertyMapper::class);
        $mapper->expects($this->never())->method('ensureLocalProperty');
        $wordpress = $this->createMock(WordPressPropertyRepository::class);
        $wordpress->method('enabled')->willReturn(false);

        $result = (new FincaraizListingReconciler($client, $mapper, $wordpress))->reconcile([
            'api_key' => 'production-key',
            'client_id' => 'df03d199-be5c-4c5c-98f6-849361cb7fae',
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame('ambiguous', $result['items'][0]['state']);
        $this->assertDatabaseCount('property_sync_statuses', 0);
    }

    public function test_it_applies_a_cached_preview_without_querying_fincaraiz_again(): void
    {
        $property = Property::create(['code' => '53824', 'status' => 'active']);
        $listingId = '7be7c83d-10b1-417b-a661-484ff5ebd821';
        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->never())->method('listListingsMany');
        $mapper = $this->createMock(FincaraizPropertyMapper::class);
        $mapper->expects($this->once())->method('ensureLocalProperty')->with('53824')->willReturn($property);
        $wordpress = $this->createMock(WordPressPropertyRepository::class);
        $wordpress->method('enabled')->willReturn(false);

        $result = (new FincaraizListingReconciler($client, $mapper, $wordpress))->applyPreview([[
            'code' => '53824',
            'state' => 'ready',
            'listing_id' => $listingId,
            'fr_property_id' => '1511253',
            'status' => 4,
        ]]);

        $this->assertSame(1, $result['linked']);
        $this->assertSame('linked', $result['items'][0]['state']);
        $this->assertDatabaseHas('property_sync_statuses', [
            'property_id' => $property->id,
            'external_id' => $listingId,
            'sync_status' => 'synced',
        ]);
    }
}
