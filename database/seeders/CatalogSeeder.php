<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Feature;
use App\Models\PropertyType;
use App\Models\TransactionType;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // ─── CIUDADES ───────────────────────────────────────────────
        $cities = [
            ['dane_code' => '70001', 'name' => 'Sincelejo',   'department' => 'Sucre',     'country_code' => 'CO', 'lat' => 9.3047,  'lng' => -75.3978],
            ['dane_code' => '13001', 'name' => 'Cartagena',   'department' => 'Bolívar',   'country_code' => 'CO', 'lat' => 10.3910, 'lng' => -75.4794],
            ['dane_code' => '23001', 'name' => 'Montería',    'department' => 'Córdoba',   'country_code' => 'CO', 'lat' => 8.7479,  'lng' => -75.8814],
            ['dane_code' => '20001', 'name' => 'Valledupar',  'department' => 'Cesar',     'country_code' => 'CO', 'lat' => 10.4631, 'lng' => -73.2532],
            ['dane_code' => '08001', 'name' => 'Barranquilla','department' => 'Atlántico', 'country_code' => 'CO', 'lat' => 10.9685, 'lng' => -74.7813],
        ];
        foreach ($cities as $c) City::updateOrCreate(['dane_code' => $c['dane_code']], $c);

        // ─── TIPOS DE PROPIEDAD ─────────────────────────────────────
        $types = [
            ['slug' => 'apartamento',    'name' => 'Apartamento',    'icon' => 'fa-building',      'color' => '#3b82f6', 'is_building' => true,  'is_land' => false, 'is_commercial' => false, 'order' => 1],
            ['slug' => 'apartaestudio',  'name' => 'Apartaestudio',  'icon' => 'fa-door-open',     'color' => '#0ea5e9', 'is_building' => true,  'is_land' => false, 'is_commercial' => false, 'order' => 2],
            ['slug' => 'casa',           'name' => 'Casa',           'icon' => 'fa-house',         'color' => '#22c55e', 'is_building' => true,  'is_land' => false, 'is_commercial' => false, 'order' => 3],
            ['slug' => 'casa-campestre', 'name' => 'Casa Campestre', 'icon' => 'fa-house-chimney', 'color' => '#16a34a', 'is_building' => true,  'is_land' => false, 'is_commercial' => false, 'order' => 4],
            ['slug' => 'finca',          'name' => 'Finca',          'icon' => 'fa-tractor',       'color' => '#65a30d', 'is_building' => false, 'is_land' => true,  'is_commercial' => false, 'order' => 5],
            ['slug' => 'lote',           'name' => 'Lote',           'icon' => 'fa-map',           'color' => '#a3a3a3', 'is_building' => false, 'is_land' => true,  'is_commercial' => false, 'order' => 6],
            ['slug' => 'lote-urbano',    'name' => 'Lote Urbano',    'icon' => 'fa-city',          'color' => '#737373', 'is_building' => false, 'is_land' => true,  'is_commercial' => false, 'order' => 7],
            ['slug' => 'oficina',        'name' => 'Oficina',        'icon' => 'fa-briefcase',     'color' => '#a855f7', 'is_building' => true,  'is_land' => false, 'is_commercial' => true,  'order' => 8],
            ['slug' => 'local',          'name' => 'Local Comercial','icon' => 'fa-store',         'color' => '#ec4899', 'is_building' => true,  'is_land' => false, 'is_commercial' => true,  'order' => 9],
            ['slug' => 'bodega',         'name' => 'Bodega',         'icon' => 'fa-warehouse',     'color' => '#f59e0b', 'is_building' => true,  'is_land' => false, 'is_commercial' => true,  'order' => 10],
            ['slug' => 'edificio',       'name' => 'Edificio',       'icon' => 'fa-city',          'color' => '#1e40af', 'is_building' => true,  'is_land' => false, 'is_commercial' => true,  'order' => 11],
            ['slug' => 'consultorio',    'name' => 'Consultorio',    'icon' => 'fa-stethoscope',   'color' => '#06b6d4', 'is_building' => true,  'is_land' => false, 'is_commercial' => true,  'order' => 12],
        ];
        foreach ($types as $t) PropertyType::updateOrCreate(['slug' => $t['slug']], $t);

        // ─── TIPOS DE TRANSACCIÓN ───────────────────────────────────
        $transactions = [
            ['slug' => 'sale',      'name' => 'Venta',                'has_sale_price' => true,  'has_rent_price' => false, 'has_admin_price' => false, 'order' => 1],
            ['slug' => 'rent',      'name' => 'Arriendo',             'has_sale_price' => false, 'has_rent_price' => true,  'has_admin_price' => true,  'order' => 2],
            ['slug' => 'sale_rent', 'name' => 'Venta y Arriendo',     'has_sale_price' => true,  'has_rent_price' => true,  'has_admin_price' => true,  'order' => 3],
            ['slug' => 'vacation',  'name' => 'Temporada Turística',  'has_sale_price' => false, 'has_rent_price' => true,  'has_admin_price' => false, 'order' => 4],
        ];
        foreach ($transactions as $t) TransactionType::updateOrCreate(['slug' => $t['slug']], $t);

        // ─── CARACTERÍSTICAS (FEATURES) ─────────────────────────────
        $features = [
            // Internas (dentro del inmueble)
            ['group' => 'internal', 'slug' => 'aire-acondicionado', 'name' => 'Aire acondicionado',  'icon' => 'fa-snowflake'],
            ['group' => 'internal', 'slug' => 'cocina-integral',    'name' => 'Cocina integral',     'icon' => 'fa-kitchen-set'],
            ['group' => 'internal', 'slug' => 'closets',            'name' => 'Closets',             'icon' => 'fa-door-closed'],
            ['group' => 'internal', 'slug' => 'amoblado',           'name' => 'Amoblado',            'icon' => 'fa-couch'],
            ['group' => 'internal', 'slug' => 'calentador',         'name' => 'Calentador',          'icon' => 'fa-fire'],
            ['group' => 'internal', 'slug' => 'balcon',             'name' => 'Balcón',              'icon' => 'fa-border-top-left'],
            ['group' => 'internal', 'slug' => 'sala',               'name' => 'Sala',                'icon' => 'fa-tv'],
            ['group' => 'internal', 'slug' => 'comedor',            'name' => 'Comedor',             'icon' => 'fa-utensils'],
            ['group' => 'internal', 'slug' => 'estudio',            'name' => 'Estudio',             'icon' => 'fa-book'],
            ['group' => 'internal', 'slug' => 'habitacion-servicio','name' => 'Habitación de servicio','icon' => 'fa-bed'],
            ['group' => 'internal', 'slug' => 'patio',              'name' => 'Patio',               'icon' => 'fa-tree'],
            ['group' => 'internal', 'slug' => 'terraza',            'name' => 'Terraza',             'icon' => 'fa-umbrella-beach'],

            // Externas (del conjunto/edificio)
            ['group' => 'external', 'slug' => 'piscina',            'name' => 'Piscina',             'icon' => 'fa-person-swimming'],
            ['group' => 'external', 'slug' => 'gimnasio',           'name' => 'Gimnasio',            'icon' => 'fa-dumbbell'],
            ['group' => 'external', 'slug' => 'salon-social',       'name' => 'Salón social',        'icon' => 'fa-champagne-glasses'],
            ['group' => 'external', 'slug' => 'parqueadero',        'name' => 'Parqueadero',         'icon' => 'fa-car'],
            ['group' => 'external', 'slug' => 'parqueadero-visitantes','name' => 'Parq. visitantes', 'icon' => 'fa-square-parking'],
            ['group' => 'external', 'slug' => 'ascensor',           'name' => 'Ascensor',            'icon' => 'fa-elevator'],
            ['group' => 'external', 'slug' => 'porteria-24-7',      'name' => 'Portería 24/7',       'icon' => 'fa-shield-halved'],
            ['group' => 'external', 'slug' => 'zonas-verdes',       'name' => 'Zonas verdes',        'icon' => 'fa-leaf'],
            ['group' => 'external', 'slug' => 'cancha-deportiva',   'name' => 'Cancha deportiva',    'icon' => 'fa-futbol'],
            ['group' => 'external', 'slug' => 'bbq',                'name' => 'Zona BBQ',            'icon' => 'fa-fire-burner'],
            ['group' => 'external', 'slug' => 'juegos-infantiles',  'name' => 'Juegos infantiles',   'icon' => 'fa-children'],
            ['group' => 'external', 'slug' => 'sauna',              'name' => 'Sauna / turco',       'icon' => 'fa-hot-tub-person'],
            ['group' => 'external', 'slug' => 'jacuzzi',            'name' => 'Jacuzzi',             'icon' => 'fa-spa'],
            ['group' => 'external', 'slug' => 'cancha-tenis',       'name' => 'Cancha de tenis',     'icon' => 'fa-baseball'],

            // Alrededores
            ['group' => 'surrounding', 'slug' => 'colegio',          'name' => 'Cerca a colegio',         'icon' => 'fa-school'],
            ['group' => 'surrounding', 'slug' => 'universidad',      'name' => 'Cerca a universidad',     'icon' => 'fa-graduation-cap'],
            ['group' => 'surrounding', 'slug' => 'supermercado',     'name' => 'Cerca a supermercado',    'icon' => 'fa-cart-shopping'],
            ['group' => 'surrounding', 'slug' => 'hospital',         'name' => 'Cerca a hospital',        'icon' => 'fa-hospital'],
            ['group' => 'surrounding', 'slug' => 'parque',           'name' => 'Cerca a parque',          'icon' => 'fa-tree'],
            ['group' => 'surrounding', 'slug' => 'transporte',       'name' => 'Transporte público',      'icon' => 'fa-bus'],
            ['group' => 'surrounding', 'slug' => 'centro-comercial', 'name' => 'Cerca a centro comercial', 'icon' => 'fa-bag-shopping'],
            ['group' => 'surrounding', 'slug' => 'zona-rosa',        'name' => 'Zona rosa / restaurantes','icon' => 'fa-martini-glass'],
            ['group' => 'surrounding', 'slug' => 'iglesia',          'name' => 'Cerca a iglesia',         'icon' => 'fa-church'],

            // Reglas
            ['group' => 'rule', 'slug' => 'mascotas',     'name' => 'Acepta mascotas',       'icon' => 'fa-paw'],
            ['group' => 'rule', 'slug' => 'ninos',        'name' => 'Acepta niños',          'icon' => 'fa-children'],
            ['group' => 'rule', 'slug' => 'familias',     'name' => 'Solo familias',         'icon' => 'fa-people-roof'],
            ['group' => 'rule', 'slug' => 'solteros',     'name' => 'Acepta solteros',       'icon' => 'fa-user'],
            ['group' => 'rule', 'slug' => 'estudiantes',  'name' => 'Acepta estudiantes',    'icon' => 'fa-user-graduate'],
            ['group' => 'rule', 'slug' => 'no-mascotas',  'name' => 'No mascotas',           'icon' => 'fa-ban'],
            ['group' => 'rule', 'slug' => 'no-ninos',     'name' => 'No niños',              'icon' => 'fa-ban'],
        ];
        foreach ($features as $f) Feature::updateOrCreate(['group' => $f['group'], 'slug' => $f['slug']], $f);

        $this->command->info('✔ Catálogos cargados: ' . count($cities) . ' ciudades, ' . count($types) . ' tipos, ' . count($transactions) . ' transacciones, ' . count($features) . ' características');
    }
}
