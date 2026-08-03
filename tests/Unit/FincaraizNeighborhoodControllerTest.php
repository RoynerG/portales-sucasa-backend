<?php

namespace Tests\Unit;

use App\Http\Controllers\Portal\FincaraizNeighborhoodController;
use App\Models\City;
use App\Models\Neighborhood;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FincaraizNeighborhoodControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sources.properties', 'database');
        config()->set('portals.fincaraiz.environment', 'production');
        Schema::dropIfExists('portal_mappings');
        Schema::dropIfExists('neighborhoods');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('integrations');

        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('dane_code')->unique();
            $table->string('name');
            $table->string('department');
            $table->string('country_code')->default('CO');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('neighborhoods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('city_id');
            $table->string('name');
            $table->string('postal_code')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('portal_mappings', function (Blueprint $table): void {
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
    }

    public function test_it_lists_and_saves_the_official_neighborhood_uuid(): void
    {
        $city = City::create([
            'dane_code' => '70001',
            'name' => 'Sincelejo',
            'department' => 'Sucre',
        ]);
        $neighborhood = Neighborhood::create([
            'city_id' => $city->id,
            'name' => 'Centro',
            'active' => true,
        ]);
        $controller = new FincaraizNeighborhoodController;

        $before = $controller->index(Request::create('/fincaraiz/neighborhoods', 'GET'))->getData(true)['Datos'];
        $this->assertSame(1, $before['summary']['pending']);

        $locationId = '4faca461-6a83-41cb-a57e-f3860624826a';
        $controller->update(Request::create('/fincaraiz/neighborhoods/'.$neighborhood->id, 'PATCH', [
            'location_id' => $locationId,
            'name' => 'Centro',
            'location_type' => 'NEIGHBOURHOOD',
            'country' => 'Colombia',
            'state' => 'Sucre',
            'city' => 'Sincelejo',
        ]), $neighborhood->id);

        $this->assertDatabaseHas('portal_mappings', [
            'integration_id' => 1,
            'mappable_type' => Neighborhood::class,
            'mappable_id' => $neighborhood->id,
            'external_id' => $locationId,
        ]);
        $after = $controller->index(Request::create('/fincaraiz/neighborhoods', 'GET'))->getData(true)['Datos'];
        $this->assertSame(1, $after['summary']['configured']);
        $this->assertSame($locationId, $after['neighborhoods'][0]['fincaraiz_location_id']);
    }

    public function test_it_persists_the_uuid_in_the_wordpress_neighborhood_table(): void
    {
        config()->set('sources.properties', 'wordpress');
        config()->set('database.connections.wordpress', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('wordpress');
        Schema::connection('wordpress')->create('wp_jet_cct_barrios', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('cct_status')->nullable();
            $table->string('barrio')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('departamento')->nullable();
            $table->string('codigo_postal')->nullable();
            $table->string('fincaraiz_location_id', 36)->nullable();
            $table->string('fincaraiz_location_name')->nullable();
            $table->string('fincaraiz_location_type')->nullable();
        });
        DB::connection('wordpress')->table('wp_jet_cct_barrios')->insert([
            '_ID' => 215,
            'cct_status' => 'publish',
            'barrio' => 'Centro',
            'ciudad' => 'Sincelejo',
            'departamento' => 'Sucre',
            'codigo_postal' => '700001',
        ]);
        $controller = new FincaraizNeighborhoodController;
        $locationId = '4faca461-6a83-41cb-a57e-f3860624826a';

        $controller->update(Request::create('/fincaraiz/neighborhoods/215', 'PATCH', [
            'location_id' => $locationId,
            'name' => 'Centro',
            'location_type' => 'NEIGHBOURHOOD',
            'country' => 'Colombia',
            'state' => 'Sucre',
            'city' => 'Sincelejo',
        ]), 215);

        $this->assertSame($locationId, DB::connection('wordpress')
            ->table('wp_jet_cct_barrios')
            ->where('_ID', 215)
            ->value('fincaraiz_location_id'));
        $data = $controller->index(Request::create('/fincaraiz/neighborhoods', 'GET'))->getData(true)['Datos'];
        $this->assertSame(1, $data['summary']['configured']);
        $this->assertSame('Centro', $data['neighborhoods'][0]['fincaraiz_location_name']);
    }

    public function test_it_accepts_an_official_commune_as_a_neighborhood_mapping(): void
    {
        $city = City::create([
            'dane_code' => '13052',
            'name' => 'Arjona',
            'department' => 'Bolívar',
        ]);
        $neighborhood = Neighborhood::create([
            'city_id' => $city->id,
            'name' => 'Comuna 01',
            'active' => true,
        ]);
        $controller = new FincaraizNeighborhoodController;
        $locationId = '89429bab-23ab-4dcb-8c23-2cd3503dd912';

        $controller->update(Request::create('/fincaraiz/neighborhoods/'.$neighborhood->id, 'PATCH', [
            'location_id' => $locationId,
            'name' => 'Comuna 01',
            'location_type' => 'COMMUNE',
            'country' => 'Colombia',
            'state' => 'Bolívar',
            'city' => 'Arjona',
        ]), $neighborhood->id);

        $mapping = $neighborhood->portalMappings()->firstOrFail();
        $this->assertSame($locationId, $mapping->external_id);
        $this->assertSame('COMMUNE', $mapping->extra['location_type']);

        $data = $controller->index(Request::create('/fincaraiz/neighborhoods', 'GET'))->getData(true)['Datos'];
        $this->assertSame(1, $data['summary']['configured']);
        $this->assertSame('COMMUNE', $data['neighborhoods'][0]['fincaraiz_location_type']);
    }

    public function test_it_accepts_an_official_city_as_a_neighborhood_mapping(): void
    {
        $city = City::create([
            'dane_code' => '13001',
            'name' => 'Cartagena',
            'department' => 'Bolívar',
        ]);
        $neighborhood = Neighborhood::create([
            'city_id' => $city->id,
            'name' => 'Zona rural Cartagena',
            'active' => true,
        ]);
        $controller = new FincaraizNeighborhoodController;
        $locationId = 'bf935e5f-e847-45de-a975-77231efde264';

        $controller->update(Request::create('/fincaraiz/neighborhoods/'.$neighborhood->id, 'PATCH', [
            'location_id' => $locationId,
            'name' => 'Cartagena',
            'location_type' => 'CITY',
            'country' => 'Colombia',
            'state' => 'Bolívar',
            'city' => 'Cartagena',
        ]), $neighborhood->id);

        $mapping = $neighborhood->portalMappings()->firstOrFail();
        $this->assertSame($locationId, $mapping->external_id);
        $this->assertSame('CITY', $mapping->extra['location_type']);

        $data = $controller->index(Request::create('/fincaraiz/neighborhoods', 'GET'))->getData(true)['Datos'];
        $this->assertSame(1, $data['summary']['configured']);
        $this->assertSame('CITY', $data['neighborhoods'][0]['fincaraiz_location_type']);
    }
}
