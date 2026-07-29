<?php

namespace Tests\Unit;

use App\Services\Portals\CiencuadrasActiveProperties;
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
}
