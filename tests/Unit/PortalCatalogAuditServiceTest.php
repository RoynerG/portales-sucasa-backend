<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\PortalCredential;
use App\Services\PortalCatalogAuditService;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\MercadoLibreClient;
use App\Services\Portals\ProppitClient;
use App\Services\WordPressPropertyRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class PortalCatalogAuditServiceTest extends TestCase
{
    public function test_comparison_reports_missing_extra_and_inactive_references(): void
    {
        $service = new PortalCatalogAuditService(
            Mockery::mock(WordPressPropertyRepository::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
            Mockery::mock(FincaraizClient::class),
            Mockery::mock(MercadoLibreClient::class),
            Mockery::mock(ProppitClient::class),
        );
        $integration = new Integration(['name' => 'Portal', 'slug' => 'portal', 'icon' => 'fa-plug']);
        $method = new ReflectionMethod($service, 'comparisonResult');

        $result = $method->invoke(
            $service,
            $integration,
            collect(['100', '200', '300']),
            collect(['100', '200', '999']),
            collect(['100', '200', '300', '400']),
            'Inventario completo',
            'Prueba'
        );

        $this->assertSame('differences', $result['status']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(['300'], $result['details']['missing']);
        $this->assertSame(['999'], $result['details']['extra']);
        $this->assertSame(['300', '400'], $result['details']['inactive']);
    }

    public function test_fincaraiz_audit_filters_non_array_rows_without_breaking_pagination(): void
    {
        config()->set('portals.fincaraiz.api_key', 'test-key');
        config()->set('portals.fincaraiz.client_id', 'test-client');
        config()->set('portals.fincaraiz.environment', 'production');

        $fincaraiz = Mockery::mock(FincaraizClient::class);
        $fincaraiz->shouldReceive('getClients')
            ->once()
            ->with('test-key')
            ->andReturn([
                'ok' => true,
                'data' => [[
                    'id' => 'test-client',
                    'initial_quota' => 700,
                    'used_quota' => 2,
                    'remained_quota' => 698,
                    'percentage_used_quota' => 0.3,
                ]],
            ]);
        $fincaraiz->shouldReceive('listListings')
            ->once()
            ->with('test-key', 'test-client', 1, 100)
            ->andReturn([
                'ok' => true,
                'data' => [
                    'results' => [
                        ['id' => 'listing-1', 'status' => 4, 'external_code' => '100'],
                        ['id' => 'listing-duplicate', 'status' => 4, 'external_code' => '100'],
                        null,
                        'invalid-row',
                        ['id' => 'listing-2', 'status' => 2, 'external_code' => '200'],
                    ],
                    'count' => 5,
                    'next' => null,
                ],
            ]);

        $service = new class(Mockery::mock(WordPressPropertyRepository::class), Mockery::mock(CiencuadrasActiveProperties::class), $fincaraiz, Mockery::mock(MercadoLibreClient::class), Mockery::mock(ProppitClient::class)) extends PortalCatalogAuditService
        {
            protected function registryReferences(Integration $integration, ?string $environment): Collection
            {
                return collect();
            }
        };

        $integration = new Integration(['name' => 'Fincaraíz', 'slug' => 'fincaraiz', 'icon' => 'fa-house']);
        $method = new ReflectionMethod($service, 'auditFincaraiz');
        $result = $method->invoke($service, $integration, collect(['100']), null);

        $this->assertSame('coordinated', $result['status']);
        $this->assertSame(1, $result['remote_active']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame([], $result['details']['unknown_remote']);
        $this->assertSame([
            'initial' => 700,
            'used' => 2,
            'remaining' => 698,
            'percentage_used' => 0.3,
        ], $result['quota']);
        $this->assertSame(5, $result['inventory']['total']);
        $this->assertSame(['2' => 1, '4' => 2], $result['inventory']['status_counts']);
        $this->assertSame(1, $result['inventory']['duplicate_active']);
        $this->assertSame(1, $result['inventory']['repeated_api_rows']);
        $this->assertSame('listing_api', $result['inventory']['source']);
        $this->assertSame(1, $result['inventory']['duplicate_codes']);
        $this->assertSame(0, $result['inventory']['unlinked_active']);
        $this->assertSame(['100 · listing_id listing-duplicate'], $result['details']['duplicate_active']);
        $this->assertSame(0, $result['quota_discrepancy']['difference']);
    }

    public function test_fincaraiz_audit_uses_official_export_when_it_matches_used_quota(): void
    {
        config()->set('portals.fincaraiz.api_key', 'test-key');
        config()->set('portals.fincaraiz.client_id', 'test-client');
        config()->set('portals.fincaraiz.environment', 'production');

        $fincaraiz = Mockery::mock(FincaraizClient::class);
        $fincaraiz->shouldReceive('getClients')->once()->andReturn([
            'ok' => true,
            'data' => [[
                'id' => 'test-client',
                'initial_quota' => 700,
                'used_quota' => 2,
                'remained_quota' => 698,
                'percentage_used_quota' => 0.3,
            ]],
        ]);
        $fincaraiz->shouldReceive('listListings')->once()->andReturn([
            'ok' => true,
            'data' => [
                'results' => [['id' => 'listing-1', 'status' => 4, 'external_code' => '100']],
                'count' => 1,
                'next' => null,
            ],
        ]);

        $service = new class(Mockery::mock(WordPressPropertyRepository::class), Mockery::mock(CiencuadrasActiveProperties::class), $fincaraiz, Mockery::mock(MercadoLibreClient::class), Mockery::mock(ProppitClient::class)) extends PortalCatalogAuditService
        {
            protected function registryReferences(Integration $integration, ?string $environment): Collection
            {
                return collect();
            }
        };

        $keyMethod = new ReflectionMethod($service, 'fincaraizExportKey');
        Cache::put($keyMethod->invoke($service, null, 'test-client'), [
            'filename' => 'Exportable.xlsx',
            'codes' => ['100', '200'],
            'property_ids_count' => 2,
            'imported_at' => now()->toIso8601String(),
        ], now()->addMinute());

        $integration = new Integration(['name' => 'Fincaraíz', 'slug' => 'fincaraiz', 'icon' => 'fa-house']);
        $method = new ReflectionMethod($service, 'auditFincaraiz');
        $result = $method->invoke($service, $integration, collect(['100', '200']), null);

        $this->assertSame(2, $result['remote_active']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(0, $result['missing']);
        $this->assertSame('office_export', $result['inventory']['source']);
        $this->assertSame(1, $result['inventory']['unique_active_codes']);
        $this->assertTrue($result['official_export']['matches_quota']);
        $this->assertSame(2, $result['official_export']['active_count']);
    }

    public function test_fincaraiz_audit_adds_new_api_codes_to_the_permanent_snapshot(): void
    {
        config()->set('portals.fincaraiz.api_key', 'test-key');
        config()->set('portals.fincaraiz.client_id', 'test-client');
        config()->set('portals.fincaraiz.environment', 'production');

        $fincaraiz = Mockery::mock(FincaraizClient::class);
        $fincaraiz->shouldReceive('getClients')->once()->andReturn([
            'ok' => true,
            'data' => [[
                'id' => 'test-client',
                'initial_quota' => 700,
                'used_quota' => 3,
                'remained_quota' => 697,
                'percentage_used_quota' => 0.4,
            ]],
        ]);
        $fincaraiz->shouldReceive('listListings')->once()->andReturn([
            'ok' => true,
            'data' => [
                'results' => [
                    ['id' => 'listing-1', 'status' => 4, 'external_code' => '100'],
                    ['id' => 'listing-3', 'status' => 4, 'external_code' => '300'],
                ],
                'count' => 2,
                'next' => null,
            ],
        ]);

        $service = new class(Mockery::mock(WordPressPropertyRepository::class), Mockery::mock(CiencuadrasActiveProperties::class), $fincaraiz, Mockery::mock(MercadoLibreClient::class), Mockery::mock(ProppitClient::class)) extends PortalCatalogAuditService
        {
            protected function registryReferences(Integration $integration, ?string $environment): Collection
            {
                return collect();
            }

            protected function pausedRegistryCodes(Integration $integration, ?string $environment): Collection
            {
                return collect();
            }
        };

        $keyMethod = new ReflectionMethod($service, 'fincaraizExportKey');
        $key = $keyMethod->invoke($service, null, 'test-client');
        Cache::put($key, [
            'filename' => 'Exportable.xlsx',
            'codes' => ['100', '200'],
            'property_ids_count' => 2,
            'imported_at' => now()->toIso8601String(),
        ], now()->addMinute());

        $integration = new Integration(['name' => 'Fincaraíz', 'slug' => 'fincaraiz', 'icon' => 'fa-house']);
        $method = new ReflectionMethod($service, 'auditFincaraiz');
        $result = $method->invoke($service, $integration, collect(['100', '200', '300']), null);

        $this->assertSame(3, $result['remote_active']);
        $this->assertSame(3, $result['matched']);
        $this->assertSame('office_export', $result['inventory']['source']);
        $this->assertSame(['100', '200', '300'], Cache::get($key)['codes']);
    }

    public function test_fincaraiz_audit_keeps_unique_official_codes_when_quota_includes_a_duplicate_listing(): void
    {
        config()->set('portals.fincaraiz.api_key', 'test-key');
        config()->set('portals.fincaraiz.client_id', 'test-client');
        config()->set('portals.fincaraiz.environment', 'production');

        $fincaraiz = Mockery::mock(FincaraizClient::class);
        $fincaraiz->shouldReceive('getClients')->once()->andReturn([
            'ok' => true,
            'data' => [[
                'id' => 'test-client',
                'initial_quota' => 700,
                'used_quota' => 3,
                'remained_quota' => 697,
                'percentage_used_quota' => 0.4,
            ]],
        ]);
        $fincaraiz->shouldReceive('listListings')->once()->andReturn([
            'ok' => true,
            'data' => [
                'results' => [
                    ['id' => 'listing-1', 'status' => 4, 'external_code' => '100'],
                    ['id' => 'listing-duplicate', 'status' => 4, 'external_code' => '100'],
                ],
                'count' => 2,
                'next' => null,
            ],
        ]);

        $service = new class(Mockery::mock(WordPressPropertyRepository::class), Mockery::mock(CiencuadrasActiveProperties::class), $fincaraiz, Mockery::mock(MercadoLibreClient::class), Mockery::mock(ProppitClient::class)) extends PortalCatalogAuditService
        {
            protected function registryReferences(Integration $integration, ?string $environment): Collection
            {
                return collect();
            }
        };

        $keyMethod = new ReflectionMethod($service, 'fincaraizExportKey');
        Cache::put($keyMethod->invoke($service, null, 'test-client'), [
            'filename' => 'Exportable.xlsx',
            'codes' => ['100', '200'],
            'property_ids_count' => 2,
            'imported_at' => now()->toIso8601String(),
        ], now()->addMinute());

        $integration = new Integration(['name' => 'Fincaraíz', 'slug' => 'fincaraiz', 'icon' => 'fa-house']);
        $method = new ReflectionMethod($service, 'auditFincaraiz');
        $result = $method->invoke($service, $integration, collect(['100', '200']), null);

        $this->assertSame(2, $result['remote_active']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame('office_export', $result['inventory']['source']);
        $this->assertFalse($result['official_export']['matches_quota']);
    }

    public function test_fincaraiz_audit_rebuilds_the_snapshot_from_saved_listing_references(): void
    {
        config()->set('portals.fincaraiz.api_key', 'test-key');
        config()->set('portals.fincaraiz.client_id', 'test-client');
        config()->set('portals.fincaraiz.environment', 'production');

        $fincaraiz = Mockery::mock(FincaraizClient::class);
        $fincaraiz->shouldReceive('getClients')->once()->andReturn([
            'ok' => true,
            'data' => [[
                'id' => 'test-client',
                'initial_quota' => 700,
                'used_quota' => 3,
                'remained_quota' => 697,
                'percentage_used_quota' => 0.4,
            ]],
        ]);
        $fincaraiz->shouldReceive('listListings')->once()->andReturn([
            'ok' => true,
            'data' => [
                'results' => [
                    ['id' => 'listing-1', 'status' => 4, 'external_code' => '100'],
                    ['id' => 'listing-duplicate', 'status' => 4, 'external_code' => '100'],
                ],
                'count' => 2,
                'next' => null,
            ],
        ]);

        $service = new class(Mockery::mock(WordPressPropertyRepository::class), Mockery::mock(CiencuadrasActiveProperties::class), $fincaraiz, Mockery::mock(MercadoLibreClient::class), Mockery::mock(ProppitClient::class)) extends PortalCatalogAuditService
        {
            protected function registryReferences(Integration $integration, ?string $environment): Collection
            {
                return collect(['listing-1' => '100', 'listing-2' => '200']);
            }

            protected function syncedRegistryCodes(Integration $integration, ?string $environment): Collection
            {
                return collect(['100', '200']);
            }

            protected function pausedRegistryCodes(Integration $integration, ?string $environment): Collection
            {
                return collect();
            }
        };

        $integration = new Integration(['name' => 'Fincaraíz', 'slug' => 'fincaraiz', 'icon' => 'fa-house']);
        $method = new ReflectionMethod($service, 'auditFincaraiz');
        $result = $method->invoke($service, $integration, collect(['100', '200']), null);

        $this->assertSame(2, $result['remote_active']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame('office_export', $result['inventory']['source']);
        $this->assertSame('Reconstruido desde referencias guardadas', $result['official_export']['filename']);
    }

    public function test_fincaraiz_audit_migrates_the_temporary_export_to_the_credential(): void
    {
        Schema::dropIfExists('portal_credentials');
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

        $credential = PortalCredential::create([
            'user_id' => 15,
            'integration_id' => 8,
            'data' => ['client_id' => 'test-client', 'dual_offer' => 'rent'],
        ]);
        $snapshot = [
            'filename' => 'Exportable.xlsx',
            'client_id' => 'test-client',
            'environment' => 'production',
            'active_count' => 2,
            'codes' => ['100', '200'],
            'property_ids_count' => 2,
            'imported_at' => now()->toIso8601String(),
        ];
        config()->set('portals.fincaraiz.environment', 'production');

        $service = new PortalCatalogAuditService(
            Mockery::mock(WordPressPropertyRepository::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
            Mockery::mock(FincaraizClient::class),
            Mockery::mock(MercadoLibreClient::class),
            Mockery::mock(ProppitClient::class),
        );
        $keyMethod = new ReflectionMethod($service, 'fincaraizExportKey');
        Cache::put($keyMethod->invoke($service, 15, 'test-client'), $snapshot, now()->addMinute());

        $method = new ReflectionMethod($service, 'fincaraizExport');
        $result = $method->invoke($service, $credential, 15, 'test-client');
        $storedData = $credential->fresh()->data;

        $this->assertSame($snapshot, $result);
        $this->assertSame('rent', $storedData['dual_offer']);
        $this->assertSame($snapshot, $storedData['fincaraiz_catalog_snapshot']);
    }

    public function test_fincaraiz_audit_applies_the_bundled_export_only_to_its_original_credential(): void
    {
        Schema::dropIfExists('portal_credentials');
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

        $credential = PortalCredential::forceCreate([
            'id' => 18,
            'user_id' => 1,
            'integration_id' => 8,
            'data' => ['client_id' => 'production-client'],
        ]);
        $service = new PortalCatalogAuditService(
            Mockery::mock(WordPressPropertyRepository::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
            Mockery::mock(FincaraizClient::class),
            Mockery::mock(MercadoLibreClient::class),
            Mockery::mock(ProppitClient::class),
        );
        $method = new ReflectionMethod($service, 'seededFincaraizExport');
        $snapshot = $method->invoke($service, $credential, 1, 'production-client');
        $storedData = $credential->fresh()->data;

        $this->assertSame(509, $snapshot['active_count']);
        $this->assertCount(509, $snapshot['codes']);
        $this->assertTrue($storedData['fincaraiz_catalog_seed_2026_08_12_applied']);
        $this->assertSame($snapshot, $storedData['fincaraiz_catalog_snapshot']);
    }
}
