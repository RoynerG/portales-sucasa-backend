<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Consultant;
use App\Models\Feature;
use App\Models\Neighborhood;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\PropertySyncStatus;
use App\Models\PropertyType;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Database\Seeder;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        $sincelejo = City::where('dane_code', '70001')->firstOrFail();
        $neighborhoods = Neighborhood::where('city_id', $sincelejo->id)->get();
        $consultants = Consultant::all();
        $admin = User::where('email', 'admin@sucasa.com')->first();
        $saleTx = TransactionType::where('slug', 'sale')->first();
        $rentTx = TransactionType::where('slug', 'rent')->first();
        $bothTx = TransactionType::where('slug', 'sale_rent')->first();

        $samples = [
            [
                'code' => 'SC-0001',
                'title' => 'Apartamento moderno en el centro',
                'type' => 'apartamento',
                'tx' => $saleTx,
                'sale_price' => 250000000,
                'rent_price' => null,
                'bedrooms' => 3, 'bathrooms' => 2, 'half_bathrooms' => 1,
                'parking_spaces' => 1, 'parking_type' => 'covered',
                'floor_number' => 5, 'age_years' => 5,
                'stratum' => 4, 'furnished' => false,
                'area_built' => 85, 'area_private' => 78,
                'condition' => 'used',
                'in_closed_complex' => true, 'project_name' => 'Torre Central',
                'address' => 'Calle 23 # 20-15',
                'neighborhood' => 'Centro',
                'features_internal' => ['cocina-integral', 'closets', 'sala', 'comedor', 'balcon'],
                'features_external' => ['piscina', 'gimnasio', 'salon-social', 'parqueadero', 'ascensor', 'porteria-24-7'],
                'features_surrounding' => ['colegio', 'supermercado', 'transporte', 'centro-comercial'],
                'status' => 'active',
                'featured' => true,
            ],
            [
                'code' => 'SC-0002',
                'title' => 'Casa campestre con piscina',
                'type' => 'casa-campestre',
                'tx' => $saleTx,
                'sale_price' => 480000000,
                'rent_price' => null,
                'bedrooms' => 4, 'bathrooms' => 3, 'half_bathrooms' => 1,
                'parking_spaces' => 2, 'parking_type' => 'private',
                'floor_number' => null, 'age_years' => 8,
                'stratum' => 5, 'furnished' => false,
                'area_built' => 220, 'area_land' => 1200,
                'condition' => 'used',
                'in_closed_complex' => true,
                'address' => 'Vereda Las Palmas, Kilómetro 5',
                'neighborhood' => 'Villa Country',
                'features_internal' => ['cocina-integral', 'closets', 'sala', 'comedor', 'terraza', 'estudio', 'habitacion-servicio', 'patio'],
                'features_external' => ['piscina', 'zonas-verdes', 'cancha-deportiva', 'bbq', 'parqueadero'],
                'features_surrounding' => [],
                'status' => 'active',
                'featured' => true,
            ],
            [
                'code' => 'SC-0003',
                'title' => 'Apartamento en arriendo - Boston',
                'type' => 'apartamento',
                'tx' => $rentTx,
                'sale_price' => null,
                'rent_price' => 1800000,
                'admin_price' => 250000,
                'bedrooms' => 2, 'bathrooms' => 2,
                'parking_spaces' => 1, 'parking_type' => 'uncovered',
                'floor_number' => 2, 'age_years' => 12,
                'stratum' => 3, 'furnished' => false,
                'area_built' => 65, 'area_private' => 62,
                'condition' => 'used',
                'address' => 'Carrera 18 # 15-30',
                'neighborhood' => 'Boston',
                'features_internal' => ['cocina-integral', 'sala', 'comedor'],
                'features_external' => ['parqueadero'],
                'features_surrounding' => ['colegio', 'transporte'],
                'status' => 'active',
            ],
            [
                'code' => 'SC-0004',
                'title' => 'Local comercial sobre vía principal',
                'type' => 'local',
                'tx' => $saleTx,
                'sale_price' => 320000000,
                'rent_price' => null,
                'bedrooms' => null, 'bathrooms' => 1,
                'parking_spaces' => null, 'parking_type' => null,
                'floor_number' => 1, 'age_years' => 10,
                'stratum' => 4, 'furnished' => false,
                'area_built' => 120,
                'condition' => 'used',
                'address' => 'Calle 22 # 18-45',
                'neighborhood' => 'Centro',
                'features_internal' => [],
                'features_external' => [],
                'features_surrounding' => ['transporte', 'centro-comercial', 'zona-rosa'],
                'status' => 'active',
            ],
            [
                'code' => 'SC-0005',
                'title' => 'Lote urbanizable en Pioneros',
                'type' => 'lote-urbano',
                'tx' => $saleTx,
                'sale_price' => 95000000,
                'rent_price' => null,
                'bedrooms' => null, 'bathrooms' => null,
                'parking_spaces' => null, 'parking_type' => null,
                'floor_number' => null, 'age_years' => null,
                'stratum' => 2, 'furnished' => false,
                'area_land' => 200,
                'condition' => 'new',
                'address' => 'Lote 5, Manzana 12, Urbanización Pioneros',
                'neighborhood' => 'Pioneros',
                'features_internal' => [],
                'features_external' => [],
                'features_surrounding' => ['transporte', 'colegio'],
                'status' => 'active',
            ],
            [
                'code' => 'SC-0006',
                'title' => 'Oficina amoblada en torre empresarial',
                'type' => 'oficina',
                'tx' => $rentTx,
                'sale_price' => null,
                'rent_price' => 2500000,
                'admin_price' => 450000,
                'bedrooms' => null, 'bathrooms' => 1,
                'parking_spaces' => 1, 'parking_type' => 'covered',
                'floor_number' => 8, 'age_years' => 3,
                'stratum' => 5, 'furnished' => true,
                'area_built' => 55,
                'condition' => 'used',
                'address' => 'Carrera 20 # 25-30, Torre Empresarial',
                'neighborhood' => 'Centro',
                'features_internal' => ['aire-acondicionado', 'cocina-integral'],
                'features_external' => ['ascensor', 'porteria-24-7', 'parqueadero'],
                'features_surrounding' => ['transporte', 'centro-comercial', 'zona-rosa'],
                'status' => 'active',
            ],
            [
                'code' => 'SC-0007',
                'title' => 'Bodega en zona industrial',
                'type' => 'bodega',
                'tx' => $rentTx,
                'sale_price' => null,
                'rent_price' => 4500000,
                'admin_price' => null,
                'bedrooms' => null, 'bathrooms' => 1,
                'parking_spaces' => 3, 'parking_type' => 'private',
                'floor_number' => 1, 'age_years' => 15,
                'stratum' => 3, 'furnished' => false,
                'area_built' => 400, 'area_land' => 500,
                'condition' => 'used',
                'address' => 'Vía Sincelejo - Corozal, Kilómetro 3',
                'neighborhood' => 'Cruz del Beque',
                'features_internal' => [],
                'features_external' => ['parqueadero'],
                'features_surrounding' => ['transporte'],
                'status' => 'paused',
            ],
            [
                'code' => 'SC-0008',
                'title' => 'Apartaestudio cerca a universidad',
                'type' => 'apartaestudio',
                'tx' => $rentTx,
                'sale_price' => null,
                'rent_price' => 1200000,
                'admin_price' => 180000,
                'bedrooms' => 1, 'bathrooms' => 1,
                'parking_spaces' => 0,
                'floor_number' => 3, 'age_years' => 2,
                'stratum' => 3, 'furnished' => true,
                'area_built' => 35,
                'condition' => 'new',
                'address' => 'Calle 28 # 22-50',
                'neighborhood' => 'La Ford',
                'features_internal' => ['cocina-integral', 'aire-acondicionado', 'closets'],
                'features_external' => ['porteria-24-7'],
                'features_surrounding' => ['universidad', 'transporte', 'supermercado'],
                'status' => 'active',
            ],
            [
                'code' => 'SC-0009',
                'title' => 'Casa en conjunto cerrado',
                'type' => 'casa',
                'tx' => $saleTx,
                'sale_price' => 380000000,
                'rent_price' => null,
                'bedrooms' => 3, 'bathrooms' => 3, 'half_bathrooms' => 1,
                'parking_spaces' => 2, 'parking_type' => 'private',
                'floor_number' => 2, 'age_years' => 6,
                'stratum' => 4, 'furnished' => false,
                'area_built' => 150, 'area_land' => 180,
                'condition' => 'used',
                'in_closed_complex' => true, 'project_name' => 'Conjunto Las Acacias',
                'address' => 'Casa 8, Conjunto Las Acacias',
                'neighborhood' => 'San Carlos',
                'features_internal' => ['cocina-integral', 'closets', 'sala', 'comedor', 'patio', 'estudio'],
                'features_external' => ['piscina', 'salon-social', 'parqueadero', 'parqueadero-visitantes', 'porteria-24-7', 'zonas-verdes', 'juegos-infantiles', 'cancha-deportiva'],
                'features_surrounding' => ['colegio', 'supermercado'],
                'status' => 'active',
            ],
            [
                'code' => 'SC-0010',
                'title' => 'Apartamento en venta y arriendo',
                'type' => 'apartamento',
                'tx' => $bothTx,
                'sale_price' => 220000000,
                'rent_price' => 1500000,
                'admin_price' => 220000,
                'bedrooms' => 3, 'bathrooms' => 2,
                'parking_spaces' => 1, 'parking_type' => 'covered',
                'floor_number' => 4, 'age_years' => 4,
                'stratum' => 4, 'furnished' => false,
                'area_built' => 78,
                'condition' => 'used',
                'address' => 'Calle 25 # 18-20',
                'neighborhood' => 'El Carmen',
                'features_internal' => ['cocina-integral', 'closets', 'sala', 'comedor', 'balcon'],
                'features_external' => ['parqueadero', 'ascensor', 'porteria-24-7'],
                'features_surrounding' => ['colegio', 'transporte', 'supermercado'],
                'status' => 'active',
            ],
        ];

        foreach ($samples as $i => $s) {
            $type = PropertyType::where('slug', $s['type'])->firstOrFail();
            $neighborhood = $neighborhoods->firstWhere('name', $s['neighborhood']) ?? $neighborhoods->random();
            $consultant = $consultants->random();

            $property = Property::updateOrCreate(
                ['code' => $s['code']],
                [
                    'title' => $s['title'],
                    'description' => "Excelente {$type->name} en {$neighborhood->name}, Sincelejo. Estrato {$s['stratum']}, " .
                                     ($s['area_built'] ?? $s['area_land'] ?? 0) . " m². " .
                                     ($s['bedrooms'] ? "{$s['bedrooms']} habitaciones, " : '') .
                                     "{$s['bathrooms']} baños. " .
                                     ($s['tx']->slug === 'rent' ? "Disponible para arriendo a \${$s['rent_price']} mensuales." : "Disponible para venta a \${$s['sale_price']}."),
                    'condition' => $s['condition'],
                    'city_id' => $sincelejo->id,
                    'neighborhood_id' => $neighborhood->id,
                    'address' => $s['address'],
                    'property_type_id' => $type->id,
                    'transaction_type_id' => $s['tx']->id,
                    'sale_price' => $s['sale_price'],
                    'rent_price' => $s['rent_price'],
                    'admin_price' => $s['admin_price'] ?? null,
                    'currency' => 'COP',
                    'price_negotiable' => false,
                    'area_total' => $s['area_built'] ?? $s['area_land'] ?? null,
                    'area_built' => $s['area_built'] ?? null,
                    'area_private' => $s['area_private'] ?? null,
                    'area_land' => $s['area_land'] ?? null,
                    'bedrooms' => $s['bedrooms'] ?? null,
                    'bathrooms' => $s['bathrooms'] ?? null,
                    'half_bathrooms' => $s['half_bathrooms'] ?? null,
                    'parking_spaces' => $s['parking_spaces'] ?? null,
                    'parking_type' => $s['parking_type'] ?? null,
                    'floor_number' => $s['floor_number'] ?? null,
                    'age_years' => $s['age_years'] ?? null,
                    'stratum' => $s['stratum'],
                    'furnished' => $s['furnished'],
                    'in_closed_complex' => $s['in_closed_complex'] ?? false,
                    'project_name' => $s['project_name'] ?? null,
                    'status' => $s['status'],
                    'featured' => $s['featured'] ?? false,
                    'published_at' => $s['status'] === 'active' ? now() : null,
                    'consultant_id' => $consultant->id,
                    'created_by' => $admin?->id,
                    'contact_name' => $consultant->name,
                    'contact_phone' => $consultant->phone,
                    'contact_whatsapp' => $consultant->whatsapp,
                    'contact_email' => $consultant->email,
                    'lat' => $sincelejo->lat + (mt_rand(-100, 100) / 1000),
                    'lng' => $sincelejo->lng + (mt_rand(-100, 100) / 1000),
                ]
            );

            // Features
            $features = collect(array_merge(
                $s['features_internal'] ?? [],
                $s['features_external'] ?? [],
                $s['features_surrounding'] ?? []
            ))->map(fn($slug) => Feature::where('slug', $slug)->first())->filter();
            $property->features()->sync($features->pluck('id'));

            // Imágenes de stock
            $stockImages = [
                'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=1200',
                'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=1200',
                'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=1200',
                'https://images.unsplash.com/photo-1505691938895-1758d7feb511?w=1200',
            ];
            foreach ($stockImages as $j => $url) {
                PropertyImage::updateOrCreate(
                    ['property_id' => $property->id, 'url' => $url],
                    [
                        'is_cover' => $j === 0,
                        'order' => $j,
                        'alt_text' => $s['title'],
                    ]
                );
            }

            // Sync statuses aleatorios
            $integrations = \App\Models\Integration::all();
            foreach ($integrations as $integration) {
                if (mt_rand(0, 100) > 30) {
                    $statuses = ['synced', 'synced', 'synced', 'error', 'paused'];
                    $status = $statuses[array_rand($statuses)];
                    PropertySyncStatus::updateOrCreate(
                        ['property_id' => $property->id, 'integration_id' => $integration->id],
                        [
                            'sync_status' => $status,
                            'external_id' => strtoupper(substr($integration->slug, 0, 2)) . '-' . mt_rand(10000, 99999),
                            'last_synced_at' => now()->subDays(mt_rand(0, 15)),
                        ]
                    );
                }
            }
        }
    }
}
