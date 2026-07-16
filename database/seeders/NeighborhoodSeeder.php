<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Neighborhood;
use Illuminate\Database\Seeder;

class NeighborhoodSeeder extends Seeder
{
    public function run(): void
    {
        $sincelejo = City::where('dane_code', '70001')->firstOrFail();

        $barrios = [
            ['name' => 'Centro',             'zone' => 'Centro',  'postal_code' => '700001'],
            ['name' => 'La Ford',            'zone' => 'Centro',  'postal_code' => '700001'],
            ['name' => 'Boston',             'zone' => 'Centro',  'postal_code' => '700001'],
            ['name' => 'El Carmen',          'zone' => 'Centro',  'postal_code' => '700001'],
            ['name' => 'Majagual',           'zone' => 'Norte',   'postal_code' => '700002'],
            ['name' => 'Pioneros',           'zone' => 'Norte',   'postal_code' => '700002'],
            ['name' => 'Las Delicias',       'zone' => 'Norte',   'postal_code' => '700002'],
            ['name' => 'Urbanización Sucre', 'zone' => 'Norte',   'postal_code' => '700002'],
            ['name' => 'San Carlos',         'zone' => 'Norte',   'postal_code' => '700002'],
            ['name' => 'Villa Madina',       'zone' => 'Norte',   'postal_code' => '700002'],
            ['name' => 'Cruz del Beque',     'zone' => 'Sur',     'postal_code' => '700003'],
            ['name' => 'Bucaramanga',        'zone' => 'Sur',     'postal_code' => '700003'],
            ['name' => 'Las Américas',       'zone' => 'Sur',     'postal_code' => '700003'],
            ['name' => '7 de Agosto',        'zone' => 'Sur',     'postal_code' => '700003'],
            ['name' => 'San Antonio',        'zone' => 'Oriente', 'postal_code' => '700004'],
            ['name' => 'La Selva',           'zone' => 'Oriente', 'postal_code' => '700004'],
            ['name' => 'Divino Niño',        'zone' => 'Oriente', 'postal_code' => '700004'],
            ['name' => 'La Trinidad',        'zone' => 'Occidente','postal_code' => '700005'],
            ['name' => 'Mochila',            'zone' => 'Occidente','postal_code' => '700005'],
            ['name' => 'Villa Country',      'zone' => 'Occidente','postal_code' => '700005'],
        ];

        foreach ($barrios as $b) {
            Neighborhood::updateOrCreate(
                ['city_id' => $sincelejo->id, 'name' => $b['name']],
                array_merge($b, ['city_id' => $sincelejo->id, 'active' => true])
            );
        }

        $this->command->info('✔ ' . count($barrios) . ' barrios de Sincelejo cargados');
    }
}
