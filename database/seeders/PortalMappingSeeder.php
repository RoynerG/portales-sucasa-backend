<?php

namespace Database\Seeders;

use App\Models\Integration;
use App\Models\Neighborhood;
use App\Models\PortalMapping;
use App\Models\PropertyType;
use Illuminate\Database\Seeder;

class PortalMappingSeeder extends Seeder
{
    public function run(): void
    {
        $ml = Integration::where('slug', 'mercadolibre')->first();
        $fr = Integration::where('slug', 'fincaraiz')->first();
        $cc = Integration::where('slug', 'ciencuadras')->first();
        $pp = Integration::where('slug', 'proppit')->first();

        // ─── Tipos de propiedad → IDs en cada portal ───────────────
        $typeMappings = [
            'apartamento' => ['ml' => 'MCO1473', 'fr' => 'apartment',       'cc' => '10', 'pp' => 'apartment'],
            'apartaestudio' => ['ml' => 'MCO1473', 'fr' => 'studio',          'cc' => '29', 'pp' => 'studio'],
            'casa' => ['ml' => 'MCO1468', 'fr' => 'house',           'cc' => '11', 'pp' => 'house'],
            'casa-campestre' => ['ml' => 'MCO1468', 'fr' => 'country-house',   'cc' => '11', 'pp' => 'country-house'],
            'finca' => ['ml' => 'MCO1493', 'fr' => 'farm',            'cc' => '12', 'pp' => 'farm'],
            'lote' => ['ml' => 'MCO1494', 'fr' => 'lot',             'cc' => '17', 'pp' => 'lot'],
            'lote-urbano' => ['ml' => 'MCO1494', 'fr' => 'lot',             'cc' => '17', 'pp' => 'urban-lot'],
            'oficina' => ['ml' => 'MCO506',  'fr' => 'office',          'cc' => '13', 'pp' => 'office'],
            'local' => ['ml' => 'MCO506',  'fr' => 'commercial',      'cc' => '16', 'pp' => 'commercial'],
            'bodega' => ['ml' => 'MCO506',  'fr' => 'warehouse',       'cc' => '15', 'pp' => 'warehouse'],
            'edificio' => ['ml' => 'MCO1473', 'fr' => 'building',        'cc' => '21', 'pp' => 'building'],
            'consultorio' => ['ml' => 'MCO506',  'fr' => 'consulting-room', 'cc' => '13', 'pp' => 'office'],
        ];
        foreach ($typeMappings as $slug => $m) {
            $type = PropertyType::where('slug', $slug)->first();
            if (! $type) {
                continue;
            }
            if ($ml && $m['ml']) {
                PortalMapping::updateOrCreate(
                    ['integration_id' => $ml->id, 'mappable_type' => PropertyType::class, 'mappable_id' => $type->id],
                    ['external_id' => $m['ml'], 'external_name' => $type->name]
                );
            }
            if ($fr && $m['fr']) {
                PortalMapping::updateOrCreate(
                    ['integration_id' => $fr->id, 'mappable_type' => PropertyType::class, 'mappable_id' => $type->id],
                    ['external_id' => $m['fr'], 'external_name' => $type->name]
                );
            }
            if ($cc && $m['cc']) {
                PortalMapping::updateOrCreate(
                    ['integration_id' => $cc->id, 'mappable_type' => PropertyType::class, 'mappable_id' => $type->id],
                    ['external_id' => $m['cc'], 'external_name' => $type->name]
                );
            }
            if ($pp && $m['pp']) {
                PortalMapping::updateOrCreate(
                    ['integration_id' => $pp->id, 'mappable_type' => PropertyType::class, 'mappable_id' => $type->id],
                    ['external_id' => $m['pp'], 'external_name' => $type->name]
                );
            }
        }

        // ─── Barrios de Sincelejo → IDs en portales ────────────────
        // Los IDs reales hay que consultarlos con cada portal. Aquí van ejemplos vacíos.
        $barrios = Neighborhood::all();
        foreach ($barrios as $n) {
            if ($ml) {
                PortalMapping::firstOrCreate(
                    ['integration_id' => $ml->id, 'mappable_type' => Neighborhood::class, 'mappable_id' => $n->id],
                    ['external_id' => 'TUxN'.strtoupper(substr(md5($n->name), 0, 10)), 'external_name' => $n->name]
                );
            }
            if ($fr) {
                PortalMapping::firstOrCreate(
                    ['integration_id' => $fr->id, 'mappable_type' => Neighborhood::class, 'mappable_id' => $n->id],
                    ['external_id' => (string) $n->id, 'external_name' => $n->name]
                );
            }
            if ($cc) {
                PortalMapping::firstOrCreate(
                    ['integration_id' => $cc->id, 'mappable_type' => Neighborhood::class, 'mappable_id' => $n->id],
                    ['external_id' => 'SIN-'.$n->id, 'external_name' => $n->name]
                );
            }
            if ($pp) {
                PortalMapping::firstOrCreate(
                    ['integration_id' => $pp->id, 'mappable_type' => Neighborhood::class, 'mappable_id' => $n->id],
                    ['external_id' => 'neighborhood-'.$n->id, 'external_name' => $n->name]
                );
            }
        }

        $this->command->info('✔ Mapeos de portales cargados (tipos: '.count($typeMappings).', barrios: '.count($barrios).')');
    }
}
