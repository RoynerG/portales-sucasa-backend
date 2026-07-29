<?php

namespace Tests\Unit;

use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasClient;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class CiencuadrasActivePropertiesTest extends TestCase
{
    #[DataProvider('inventoryStates')]
    public function test_it_distinguishes_active_and_historical_inventory_rows(
        array $property,
        bool $expected
    ): void {
        $method = new ReflectionMethod(CiencuadrasActiveProperties::class, 'isActiveInventoryProperty');
        $actual = $method->invoke(app(CiencuadrasActiveProperties::class), $property);

        $this->assertSame($expected, $actual);
    }

    public static function inventoryStates(): array
    {
        return [
            'active label' => [['propertyCode' => '22130-100', 'active' => 'Activo'], true],
            'active status' => [['propertyCode' => '22130-101', 'status' => '4'], true],
            'deleted label' => [['propertyCode' => '22130-102', 'active' => 'Eliminado'], false],
            'deleted status' => [['propertyCode' => '22130-103', 'status' => '8'], false],
            'unknown' => [['propertyCode' => '22130-104'], false],
        ];
    }

    public function test_cached_inventory_excludes_old_p_codes(): void
    {
        $client = Mockery::mock(CiencuadrasClient::class);
        $client->shouldReceive('login')->once()->andReturn(['ok' => true, 'data' => ['token' => 'test']]);
        $client->shouldReceive('extractToken')->once()->andReturn('test-token');
        $client->shouldReceive('consultAllProperties')->once()->andReturn([
            'ok' => true,
            'data' => [
                'message' => [
                    ['propertyCode' => '22130-P101247', 'active' => 'Activo'],
                    ['propertyCode' => '22130-101247', 'active' => 'Activo'],
                ],
            ],
        ]);

        $service = new CiencuadrasActiveProperties($client);

        $this->assertSame(['101247'], $service->sourceCodes(fresh: true)?->all());
    }
}
