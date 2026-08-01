<?php

namespace Tests\Unit;

use App\Services\PortalErrorSummarizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalErrorSummarizerTest extends TestCase
{
    #[DataProvider('errorCases')]
    public function test_it_classifies_portal_errors(string $error, string $expectedType, string $expectedTitle): void
    {
        $summary = app(PortalErrorSummarizer::class)->summarize($error, [], 'error');

        $this->assertSame($expectedType, $summary['type']);
        $this->assertSame($expectedTitle, $summary['title']);
        $this->assertNotEmpty($summary['type_label']);
    }

    public static function errorCases(): array
    {
        return [
            'area' => ['Proppit requiere totalArea para lotes.', 'property_data', 'Falta el área total'],
            'coordinates' => ['Faltan coordenadas.', 'location', 'Faltan coordenadas válidas'],
            'operation' => ['Property can not be sell and rent at the same time.', 'business_rule', 'Venta y arriendo no se pueden enviar juntos'],
            'missing' => ['El inmueble que desea actualizar no existe', 'not_found', 'El portal no encontró el inmueble'],
            'photos' => ['No se pudieron cargar las fotos del inmueble', 'images', 'Fotos no aceptadas por el portal'],
        ];
    }
}
