<?php

namespace Tests\Unit;

use App\Models\Neighborhood;
use App\Models\PortalMapping;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\PropertyType;
use App\Models\TransactionType;
use App\Services\Portals\FincaraizPropertyMapper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\TestCase;

class FincaraizPropertyMapperTest extends TestCase
{
    public function test_wordpress_labels_are_normalized_before_mapping(): void
    {
        $mapper = new class extends FincaraizPropertyMapper
        {
            public function exposedTransactionSlug(?string $value): string
            {
                return $this->transactionSlug($value);
            }

            public function exposedLocalStatus(?string $value): string
            {
                return $this->localStatus($value);
            }
        };

        $this->assertSame('rent', $mapper->exposedTransactionSlug('Arriendo'));
        $this->assertSame('sale', $mapper->exposedTransactionSlug('Venta'));
        $this->assertSame('sale_rent', $mapper->exposedTransactionSlug('Arriendo / Venta'));
        $this->assertSame('active', $mapper->exposedLocalStatus('Publico'));
        $this->assertSame('draft', $mapper->exposedLocalStatus('En borrador'));
    }

    public function test_wordpress_lookup_normalizes_whitespace_in_property_codes(): void
    {
        config()->set('sources.properties', 'wordpress');
        config()->set('database.connections.wordpress', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('wordpress');
        Schema::connection('wordpress')->create('wp_jet_cct_inmuebles', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('cct_status');
            $table->string('codigo');
            $table->string('estado');
        });
        DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->insert([
            [
                'cct_status' => 'publish',
                'codigo' => '96187',
                'estado' => 'En borrador',
            ],
            [
                'cct_status' => 'publish',
                'codigo' => ' 96187 ',
                'estado' => 'Publico',
            ],
        ]);
        $mapper = new class extends FincaraizPropertyMapper
        {
            public function exposedSourceRow(string $code): ?stdClass
            {
                return $this->sourceRow($code);
            }
        };

        $this->assertSame(' 96187 ', $mapper->exposedSourceRow('96187')?->codigo);
    }

    public function test_mapper_builds_the_documented_listing_shape(): void
    {
        $property = new Property([
            'code' => '53824',
            'title' => 'Apartamento en venta',
            'description' => 'Apartamento amplio, iluminado y listo para habitar.',
            'condition' => 'remodeled',
            'address' => 'Carrera 20 # 10-15',
            'lat' => 9.30472,
            'lng' => -75.39778,
            'sale_price' => 320000000,
            'admin_price' => 250000,
            'price_negotiable' => true,
            'area_built' => 95,
            'area_private' => 88,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'parking_spaces' => 1,
            'floor_number' => 4,
            'age_years' => 10,
            'stratum' => 4,
        ]);
        $property->setRelation('propertyType', new PropertyType(['slug' => 'apartamento', 'name' => 'Apartamento']));
        $property->setRelation('transactionType', new TransactionType(['slug' => 'sale', 'name' => 'Venta']));
        $property->setRelation('neighborhood', new Neighborhood(['name' => 'Centro', 'postal_code' => '700001']));
        $property->setRelation('images', new Collection([
            new PropertyImage(['url' => 'https://img.example.com/cover.jpg', 'is_cover' => true, 'order' => 0]),
            new PropertyImage(['url' => 'https://img.example.com/second.jpg', 'order' => 1]),
        ]));
        $property->setRelation('videos', new Collection);
        $property->setRelation('features', new Collection);

        $mapped = (new FincaraizPropertyMapper)->mapProperty($property, [
            'client_id' => 'df03d199-be5c-4c5c-98f6-849361cb7fae',
            'client_agent' => 1234,
            'contact_email' => 'asesor@example.com',
            'contact_phone' => '3001234567',
            'location_id' => '1895e0a3-60b8-4a9d-858d-f2c7297b48b2',
        ]);

        $this->assertSame([], $mapped['errors']);
        $payload = $mapped['payload'];
        $this->assertSame('53824', $payload['external_code']);
        $this->assertSame('sell', $payload['offer']);
        $this->assertSame('apartment', $payload['property_type']);
        $this->assertSame(4, $payload['condition']);
        $this->assertSame(3, $payload['age']);
        $this->assertSame(95.0, $payload['area']);
        $this->assertSame(88.0, $payload['living_area']);
        $this->assertSame('+573001234567', $payload['listing_contact']['phones'][0]['phone']);
        $this->assertTrue($payload['photos'][0]['is_main']);
        $this->assertSame(1, $payload['photos'][0]['sort_order']);
        $this->assertArrayNotHasKey('title', $payload);
    }

    public function test_mapper_reports_missing_production_requirements_before_sending(): void
    {
        $property = new Property([
            'code' => 'EMPTY',
            'title' => 'Inmueble',
            'description' => '',
            'address' => '',
            'sale_price' => 0,
            'area_built' => 0,
        ]);
        $property->setRelation('propertyType', new PropertyType(['slug' => 'desconocido']));
        $property->setRelation('transactionType', new TransactionType(['slug' => 'sale']));
        $property->setRelation('neighborhood', null);
        $property->setRelation('images', new Collection);
        $property->setRelation('videos', new Collection);
        $property->setRelation('features', new Collection);

        $mapped = (new FincaraizPropertyMapper)->mapProperty($property, []);

        $this->assertNotEmpty($mapped['errors']);
        $this->assertStringContainsString('FINCARAIZ_CLIENT_ID', implode(' ', $mapped['errors']));
        $this->assertStringContainsString('precio', strtolower(implode(' ', $mapped['errors'])));
        $this->assertStringContainsString('latitud', strtolower(implode(' ', $mapped['errors'])));
    }

    public function test_sale_and_rent_properties_are_always_sent_as_rent(): void
    {
        $property = new Property([
            'code' => 'DUAL-1',
            'title' => 'Casa en arriendo y venta',
            'description' => 'Casa disponible para arriendo y venta con espacios amplios.',
            'address' => 'Calle 10 # 20-30',
            'sale_price' => 450000000,
            'rent_price' => 3500000,
            'area_built' => 120,
            'lat' => 10.4,
            'lng' => -75.5,
        ]);
        $property->setRelation('propertyType', new PropertyType(['slug' => 'casa']));
        $property->setRelation('transactionType', new TransactionType(['slug' => 'sale_rent', 'name' => 'Arriendo/Venta']));
        $property->setRelation('neighborhood', null);
        $property->setRelation('images', new Collection);
        $property->setRelation('videos', new Collection);
        $property->setRelation('features', new Collection);

        $mapped = (new FincaraizPropertyMapper)->mapProperty($property, [
            'dual_offer' => 'sale',
        ]);

        $this->assertSame('rent', $mapped['payload']['offer']);
        $this->assertSame(3500000.0, $mapped['payload']['price']);
    }

    public function test_it_saves_the_official_location_for_the_local_neighborhood(): void
    {
        Schema::dropIfExists('portal_mappings');
        Schema::dropIfExists('integrations');
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
        });
        Schema::create('portal_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('mappable_type');
            $table->unsignedBigInteger('mappable_id');
            $table->string('external_id');
            $table->string('external_name')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'mappable_type', 'mappable_id']);
        });
        DB::table('integrations')->insert(['id' => 1, 'slug' => 'fincaraiz']);

        $property = new Property;
        $property->id = 99;
        $property->neighborhood_id = 7;
        $property->exists = true;

        $locationId = '4faca461-6a83-41cb-a57e-f3860624826a';
        $mapper = $this->getMockBuilder(FincaraizPropertyMapper::class)
            ->onlyMethods(['map'])
            ->getMock();
        $mapper->expects($this->exactly(2))
            ->method('map')
            ->willReturnOnConsecutiveCalls(
                ['property' => $property],
                ['property' => $property, 'payload' => ['locations' => ['location_main_id' => $locationId]]]
            );

        $mapped = $mapper->saveLocationMapping('FR-LOCATION', [
            'location_id' => $locationId,
            'name' => 'Centro',
            'location_type' => 'NEIGHBOURHOOD',
            'country' => 'Colombia',
            'state' => 'Sucre',
            'city' => 'Sincelejo',
        ]);

        $this->assertSame($locationId, $mapped['payload']['locations']['location_main_id']);
        $this->assertDatabaseHas('portal_mappings', [
            'mappable_type' => Neighborhood::class,
            'mappable_id' => 7,
            'external_id' => $locationId,
            'external_name' => 'Centro',
        ]);
        $this->assertSame('NEIGHBOURHOOD', PortalMapping::firstOrFail()->extra['location_type']);
    }
}
