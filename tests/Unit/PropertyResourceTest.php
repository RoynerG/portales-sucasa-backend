<?php

namespace Tests\Unit;

use App\Http\Resources\PropertyResource;
use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PropertyResourceTest extends TestCase
{
    public function test_it_includes_the_monitor_error_summary_for_each_failed_portal(): void
    {
        $property = new Property([
            'code' => '74428',
            'title' => 'Lote en venta',
            'currency' => 'COP',
        ]);
        $property->setRelation('city', null);
        $property->setRelation('neighborhood', null);
        $property->setRelation('propertyType', null);
        $property->setRelation('transactionType', null);
        $property->setRelation('consultant', null);
        $property->setRelation('images', new Collection);

        $status = new PropertySyncStatus([
            'environment' => 'production',
            'portal_variant' => 'default',
            'sync_status' => 'error',
            'last_error' => 'Falta el área total del inmueble',
            'last_response' => [],
        ]);
        $status->setRelation('integration', new Integration([
            'name' => 'Proppit',
            'slug' => 'proppit',
        ]));
        $property->setRelation('syncStatuses', collect([$status]));

        $data = (new PropertyResource($property))->resolve(new Request);
        $summary = $data['sync_statuses'][0]['error_summary'];

        $this->assertSame('property_data', $summary['type']);
        $this->assertSame('Falta el área total', $summary['title']);
        $this->assertStringContainsString('mayor que cero', $summary['action']);
    }
}
