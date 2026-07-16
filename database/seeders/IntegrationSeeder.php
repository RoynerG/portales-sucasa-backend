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
                'description' => 'Portal inmobiliario #1 en Colombia. API Kong.',
                'icon' => 'fa-house-chimney',
                'color' => '#2196f3',
                'website_url' => 'https://www.fincaraiz.com.co',
                'config_schema' => ['fields' => ['api_key']],
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
                'api_class' => 'App\\Services\\Portals\\ProppitFeedGenerator',
                'description' => 'Feed XML Lifull Connect para distribución masiva.',
                'icon' => 'fa-rss',
                'color' => '#a855f7',
                'website_url' => 'https://www.proppit.com',
                'config_schema' => ['fields' => []],
                'order' => 4,
            ],
            [
                'name' => 'Google Sitemap',
                'slug' => 'google',
                'api_class' => 'App\\Services\\Portals\\GoogleSitemapGenerator',
                'description' => 'Sitemap XML para indexar en Google Search.',
                'icon' => 'fa-brands fa-google',
                'color' => '#4285f4',
                'website_url' => 'https://search.google.com/search-console',
                'config_schema' => ['fields' => []],
                'order' => 5,
            ],
        ];

        foreach ($items as $item) {
            Integration::updateOrCreate(['slug' => $item['slug']], array_merge($item, ['active' => true]));
        }
    }
}
