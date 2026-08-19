<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\PropertyController;
use App\Services\WordPressPropertyRepository;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class PropertyPortalSummaryTest extends TestCase
{
    public function test_portal_summary_forwards_the_active_property_filters(): void
    {
        $filters = [
            'vista_estado' => 'public',
            'codigo' => 'Manga',
            'estado' => 'active',
            'tipo_inmueble' => 'Apartamento',
            'tipo_negocio' => 'Venta',
            'destinacion' => 'Vivienda',
            'funcionario_id' => '8',
            'portal' => 'fincaraiz',
            'estado_portal' => 'error',
        ];
        $expected = [
            'total' => 3,
            'published' => 2,
            'not_published' => 1,
            'pending' => 0,
            'error' => 0,
        ];
        $wordpress = Mockery::mock(WordPressPropertyRepository::class);
        $wordpress->shouldReceive('enabled')->once()->andReturnTrue();
        $wordpress->shouldReceive('portalSummary')->once()->with($filters)->andReturn($expected);

        $response = (new PropertyController($wordpress))
            ->portalSummary(Request::create('/api/properties/portal-summary', 'GET', $filters));

        $this->assertSame($expected, $response->getData(true)['Datos']);
    }
}
