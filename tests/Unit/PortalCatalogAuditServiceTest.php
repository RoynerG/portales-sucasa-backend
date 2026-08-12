<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Services\PortalCatalogAuditService;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\MercadoLibreClient;
use App\Services\Portals\ProppitClient;
use App\Services\WordPressPropertyRepository;
use Illuminate\Support\Collection;
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
                        null,
                        'invalid-row',
                        ['id' => 'listing-2', 'status' => 2, 'external_code' => '200'],
                    ],
                    'count' => 4,
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
        $this->assertSame(4, $result['inventory']['total']);
        $this->assertSame(['2' => 1, '4' => 1], $result['inventory']['status_counts']);
        $this->assertSame(1, $result['quota_discrepancy']['difference']);
    }
}
