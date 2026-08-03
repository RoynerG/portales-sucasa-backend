<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Services\PortalCatalogAuditService;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\MercadoLibreClient;
use App\Services\Portals\ProppitClient;
use App\Services\WordPressPropertyRepository;
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
}
