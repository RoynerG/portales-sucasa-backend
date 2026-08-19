<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyPortalFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('property_sync_statuses');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('integrations');

        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('property_sync_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id');
            $table->foreignId('integration_id');
            $table->string('environment')->nullable();
            $table->string('portal_variant')->default('default');
            $table->string('sync_status');
            $table->timestamps();
        });
    }

    public function test_it_filters_properties_by_portal_state_in_the_current_environment(): void
    {
        config()->set('portals.fincaraiz.environment', 'production');
        config()->set('portals.ciencuadras.environment', 'production');

        $fincaraiz = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $ciencuadras = Integration::create(['name' => 'Ciencuadras', 'slug' => 'ciencuadras']);

        $error = Property::create(['code' => '100', 'title' => 'Con error']);
        $published = Property::create(['code' => '200', 'title' => 'Publicado']);
        $updating = Property::create(['code' => '300', 'title' => 'Actualizando']);
        Property::create(['code' => '400', 'title' => 'Sin registro']);

        $this->createStatus($error, $fincaraiz, 'production', 'error');
        $this->createStatus($error, $fincaraiz, 'qa', 'synced');
        $this->createStatus($published, $fincaraiz, 'production', 'synced');
        $this->createStatus($updating, $ciencuadras, 'production', 'pending');

        $this->assertSame(['200'], $this->codes('fincaraiz', 'published'));
        $this->assertSame(['100'], $this->codes('fincaraiz', 'error'));
        $this->assertSame(['300'], $this->codes(null, 'updating'));
        $this->assertSame(['100', '200'], $this->codes('fincaraiz', null));
        $this->assertSame(['100', '300', '400'], $this->codes('fincaraiz', 'not_published'));
        $this->assertSame([], $this->codes('portal-invalido', 'error'));
    }

    public function test_it_separates_public_properties_from_other_catalog_states(): void
    {
        Property::create(['code' => '100', 'title' => 'Público', 'status' => 'active']);
        Property::create(['code' => '200', 'title' => 'Arrendado', 'status' => 'rented']);
        Property::create(['code' => '300', 'title' => 'Sin estado', 'status' => null]);

        $this->assertSame(
            ['100'],
            Property::query()->inCatalogView('public')->orderBy('code')->pluck('code')->all()
        );
        $this->assertSame(
            ['200', '300'],
            Property::query()->inCatalogView('other')->orderBy('code')->pluck('code')->all()
        );
    }

    private function createStatus(
        Property $property,
        Integration $integration,
        string $environment,
        string $status
    ): void {
        PropertySyncStatus::create([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => $environment,
            'portal_variant' => 'default',
            'sync_status' => $status,
        ]);
    }

    private function codes(?string $portal, ?string $status): array
    {
        return Property::query()
            ->withPortalState($portal, $status)
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }
}
