<?php

namespace Database\Seeders;

use App\Models\Integration;
use Illuminate\Database\Seeder;

class IntegrationSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'name' => 'MercadoLibre',
                'slug' => 'mercadolibre',
                'api_class' => 'App\\Services\\Portals\\MercadoLibreClient',
                'description' => 'Marketplace líder en LATAM. Publica con OAuth2.',
                'icon' => 'fa-solid fa-store',
                'color' => '#ffc107',
                'website_url' => 'https://www.mercadolibre.com.co',
                'config_schema' => ['fields' => ['client_id', 'client_secret', 'redirect_uri']],
                'order' => 1,
            ],
            [
                'name' => 'Fincaraíz',
                'slug' => 'fincaraiz',
                'api_class' => 'App\\Services\\Portals\\FincaraizClient',
                'description' => 'API oficial asíncrona de Fincaraíz con ambientes QA y producción.',
                'icon' => 'fa-house-chimney',
                'color' => '#2196f3',
                'website_url' => 'https://www.fincaraiz.com.co',
                'config_schema' => ['fields' => ['api_key', 'client_id', 'client_agent', 'contact_email', 'contact_phone']],
                'order' => 2,
            ],
            [
                'name' => 'Ciencuadras',
                'slug' => 'ciencuadras',
                'api_class' => 'App\\Services\\Portals\\CiencuadrasClient',
                'description' => 'Portal del Banco de Occidente. Bearer token.',
                'icon' => 'fa-city',
                'color' => '#10b981',
                'website_url' => 'https://www.ciencuadras.com',
                'config_schema' => ['fields' => ['email', 'password']],
                'order' => 3,
            ],
            [
                'name' => 'Proppit',
                'slug' => 'proppit',
                'api_class' => 'App\\Services\\Portals\\ProppitClient',
                'description' => 'API real-time v2 para publicar, actualizar y despublicar inmuebles.',
                'icon' => 'fa-bolt',
                'color' => '#a855f7',
                'website_url' => 'https://www.proppit.com',
                'config_schema' => ['fields' => ['user', 'password', 'publisher_external_id']],
                'order' => 4,
            ],
        ];

        foreach ($items as $item) {
            Integration::updateOrCreate(['slug' => $item['slug']], array_merge($item, ['active' => true]));
        }
    }
}
