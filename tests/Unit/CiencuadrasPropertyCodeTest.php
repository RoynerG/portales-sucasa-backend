<?php

namespace Tests\Unit;

use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class CiencuadrasPropertyCodeTest extends TestCase
{
    #[DataProvider('propertyCodes')]
    public function test_mapper_always_builds_a_clean_property_code(string $source, string $expected): void
    {
        config(['portals.ciencuadras.property_code_prefix' => '22130-']);

        $mapper = app(CiencuadrasPropertyMapper::class);

        $this->assertSame($expected, $mapper->portalPropertyCode($source));
        $this->assertSame('22130-'.$expected, $mapper->externalCode($source));
        $this->assertSame('22130-P'.$expected, $mapper->legacyLookupCode($source));
    }

    public static function propertyCodes(): array
    {
        return [
            'source code' => ['101247', '101247'],
            'old P code' => ['P101247', '101247'],
            'prefixed old P code' => ['22130-P101247', '101247'],
            'prefixed clean code' => ['22130-101247', '101247'],
        ];
    }

    public function test_client_rejects_an_active_property_with_a_p_code(): void
    {
        $method = new ReflectionMethod(CiencuadrasClient::class, 'propertyBatch');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Se bloqueó el envío del código legado');

        $method->invoke(app(CiencuadrasClient::class), [[
            'propertyCode' => '22130-P101247',
            'status' => 'A',
        ]]);
    }

    public function test_client_allows_a_p_code_when_updating_an_existing_listing(): void
    {
        $method = new ReflectionMethod(CiencuadrasClient::class, 'propertyBatch');

        $batch = $method->invoke(app(CiencuadrasClient::class), [[
            'propertyCode' => 'P101247',
            'status' => 'A',
        ]], false);

        $this->assertSame('P101247', $batch[0]['propertyCode']);
    }

    public function test_mapper_uses_approximate_coordinates_when_address_is_hidden(): void
    {
        config([
            'portals.ciencuadras.show_address' => false,
            'portals.ciencuadras.approximate_location_precision' => 2,
            'portals.ciencuadras.default_latitude' => null,
            'portals.ciencuadras.default_longitude' => null,
        ]);

        $method = new ReflectionMethod(CiencuadrasPropertyMapper::class, 'coordinates');
        $coordinates = $method->invoke(app(CiencuadrasPropertyMapper::class), (object) [
            'latitud' => '10.5372474',
            'longitud' => '-75.3975306',
            'barrio' => 'Barrio inexistente para test',
            'ciudad' => 'Ciudad inexistente para test',
        ]);

        $this->assertSame(10.54, $coordinates['latitude']);
        $this->assertSame(-75.4, $coordinates['longitude']);
    }

    public function test_mapper_keeps_exact_coordinates_when_address_is_visible(): void
    {
        config(['portals.ciencuadras.show_address' => true]);

        $method = new ReflectionMethod(CiencuadrasPropertyMapper::class, 'coordinates');
        $coordinates = $method->invoke(app(CiencuadrasPropertyMapper::class), (object) [
            'latitud' => '10.5372474',
            'longitud' => '-75.3975306',
            'barrio' => 'Bayunca',
            'ciudad' => 'Cartagena',
        ]);

        $this->assertSame(10.5372474, $coordinates['latitude']);
        $this->assertSame(-75.3975306, $coordinates['longitude']);
    }
}
