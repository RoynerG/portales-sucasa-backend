<?php

namespace Tests\Unit;

use App\Http\Controllers\Portal\PortalAutomationController;
use App\Services\Portals\CiencuadrasActiveProperties;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class PortalAutomationSummaryTest extends TestCase
{
    public function test_it_counts_unique_published_properties_across_portals(): void
    {
        $service = Mockery::mock(CiencuadrasActiveProperties::class);
        $service->shouldReceive('sourceCodes')->andReturn(collect(['100', '200']));
        $controller = new PortalAutomationController($service);
        $items = collect([
            $this->item('ciencuadras', '100'),
            $this->item('ciencuadras', '200'),
            $this->item('proppit', '100'),
            $this->item('proppit', '300'),
            $this->item('ciencuadras', '200', 'not_synced'),
            $this->item('ciencuadras', '400', 'not_synced'),
        ]);

        $summary = $this->summary($controller, $items, 'all');

        $this->assertSame(3, $summary['synced']);
        $this->assertSame(1, $summary['not_synced']);
    }

    public function test_it_uses_ciencuadras_inventory_when_that_portal_is_selected(): void
    {
        $service = Mockery::mock(CiencuadrasActiveProperties::class);
        $service->shouldReceive('sourceCodes')->andReturn(collect(['100', '200']));
        $controller = new PortalAutomationController($service);

        $summary = $this->summary($controller, collect([
            $this->item('ciencuadras', '100'),
        ]), 'ciencuadras');

        $this->assertSame(2, $summary['synced']);
        $this->assertSame(2, $summary['portal_active']);
    }

    private function summary(PortalAutomationController $controller, $items, string $portal): array
    {
        $method = new ReflectionMethod($controller, 'summaryPayload');

        return $method->invoke($controller, $items, $portal);
    }

    private function item(string $portal, string $code, string $status = 'synced'): array
    {
        return [
            'portal' => $portal,
            'sync_status' => $status,
            'action' => 'publish',
            'last_attempt_at' => null,
            'property' => ['code' => $code],
        ];
    }
}
