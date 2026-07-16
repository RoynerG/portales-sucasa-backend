<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,             // 1. Usuarios (admin, agentes)
            IntegrationSeeder::class,      // 3. Portales (MercadoLibre, FR, CC, Proppit, Google)
        ]);

        if (config('sources.demo_data')) {
            $this->call([
                CatalogSeeder::class,          // Ciudades, tipos, transacciones, features
                NeighborhoodSeeder::class,     // Barrios de prueba
                ConsultantSeeder::class,       // Asesores de prueba
                PropertySeeder::class,         // Propiedades de ejemplo
                PortalMappingSeeder::class,    // Homologaciones placeholder
            ]);
        }
    }
}
