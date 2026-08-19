<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\WordPressHighlightAdminService;
use App\Services\WordPressHighlightService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WordPressHighlightAdminServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.wordpress', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('wordpress');

        Schema::connection('wordpress')->create('wp_jet_cct_inmuebles', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('cct_status')->default('publish');
            $table->string('codigo');
            $table->string('estado')->default('Publico');
            $table->string('tipo_inmueble')->nullable();
            $table->string('tipo_negocio')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('barrio')->nullable();
            $table->string('direccion')->nullable();
            $table->string('estrato')->nullable();
            $table->string('promocion_premium')->nullable();
            $table->string('funcionario')->nullable();
            $table->string('propietario')->nullable();
            $table->string('id_propietario')->nullable();
        });
        Schema::connection('wordpress')->create('wp_jet_cct_propietarios', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('id_propietario');
            $table->string('nombre')->nullable();
            $table->string('nombre_juridico')->nullable();
        });
        Schema::connection('wordpress')->create('wp_jet_cct_inmuebles_destacados', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('id_inmueble');
            $table->unsignedBigInteger('fecha')->nullable();
            $table->string('id_empleado')->nullable();
            $table->string('empleado')->nullable();
            $table->string('observacion_destacado')->nullable();
            $table->string('veces_destacado')->nullable();
            $table->string('oportunidad')->nullable();
            $table->string('negociable')->nullable();
            foreach (WordPressHighlightService::MARKETS as $market) $table->string($market['history_column'])->nullable();
        });
        Schema::connection('wordpress')->create('wp_posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('ID')->primary();
            $table->string('post_type');
        });
        Schema::connection('wordpress')->create('wp_postmeta', function (Blueprint $table): void {
            $table->increments('meta_id');
            $table->unsignedBigInteger('post_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        Schema::connection('wordpress')->create('wp_jet_cct_historial_del_inmueble', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('cct_status')->nullable();
            $table->unsignedBigInteger('cct_author_id')->nullable();
            $table->dateTime('cct_created')->nullable();
            $table->dateTime('cct_modified')->nullable();
            $table->string('id_empleado')->nullable();
            $table->string('id_inmueble');
            $table->unsignedBigInteger('fecha')->nullable();
            $table->string('tipo_reporte')->nullable();
            $table->text('observacion')->nullable();
            $table->string('funcionario')->nullable();
        });
        Schema::connection('wordpress')->create('wp_jet_cct_reportes_comerciales', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('cct_status')->nullable();
            $table->string('id_inmueble');
            $table->unsignedBigInteger('fecha')->nullable();
            $table->string('tipo_reporte')->nullable();
            $table->text('observacion')->nullable();
            $table->integer('valor')->default(0);
            $table->string('funcionario')->nullable();
            $table->string('id_empleado')->nullable();
            $table->unsignedBigInteger('cct_author_id')->nullable();
            $table->dateTime('cct_created')->nullable();
        });

        DB::connection('wordpress')->table('wp_jet_cct_propietarios')->insert(['id_propietario' => 'P1', 'nombre' => 'Dueña Uno']);
        DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->insert([
            ['codigo' => '101', 'estado' => 'Publico', 'tipo_inmueble' => 'Apartamento', 'tipo_negocio' => 'Venta', 'ciudad' => 'Cartagena', 'barrio' => 'Manga', 'direccion' => 'Calle 1', 'estrato' => '5', 'promocion_premium' => 'No', 'funcionario' => 'Ana', 'id_propietario' => 'P1'],
            ['codigo' => '102', 'estado' => 'Publico', 'tipo_inmueble' => 'Casa', 'tipo_negocio' => 'Arriendo', 'ciudad' => 'Cartagena', 'barrio' => 'Crespo', 'direccion' => 'Calle 2', 'estrato' => '3', 'promocion_premium' => 'No', 'funcionario' => 'Luis', 'id_propietario' => 'P1'],
        ]);
        DB::connection('wordpress')->table('wp_posts')->insert([['ID' => 101, 'post_type' => 'inmuebles'], ['ID' => 102, 'post_type' => 'inmuebles']]);
        $history = array_fill_keys(array_map(fn (array $market): string => $market['history_column'], WordPressHighlightService::MARKETS), 'No');
        $history['mercado_libre_destacados'] = 'Si';
        DB::connection('wordpress')->table('wp_jet_cct_inmuebles_destacados')->insert([...$history, 'id_inmueble' => '101', 'fecha' => 1_720_000_000, 'id_empleado' => '10', 'empleado' => 'Ana', 'observacion_destacado' => 'Oportunidad', 'veces_destacado' => '2', 'oportunidad' => 'Si', 'negociable' => 'No']);
    }

    public function test_it_lists_the_complete_consolidated_history(): void
    {
        $result = (new WordPressHighlightAdminService)->history(['mercado' => 'mercado_libre_destacados']);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('101', $result['items'][0]['code']);
        $this->assertSame('Mercado Libre', $result['items'][0]['markets'][0]['label']);
        $this->assertSame(1, $result['summary']['properties']);
    }

    public function test_it_manages_premium_and_registers_reports_in_both_histories(): void
    {
        $service = new WordPressHighlightAdminService;
        $actor = new User(['name' => 'Coordinadora', 'legacy_employee_id' => '77']);
        $actor->id = 9;

        $listed = $service->premium();
        $this->assertSame(1, $listed['summary']['available']);
        $this->assertSame(2, $listed['summary']['public']);
        $this->assertSame(1, $listed['summary']['ineligible']);
        $this->assertSame('101', $listed['items'][0]['code']);
        $this->assertTrue($listed['items'][0]['premium_synced']);
        $this->assertSame('Sincronizado', $listed['items'][0]['premium_sync_label']);

        $toggle = $service->togglePremium('101', true, $actor);
        $this->assertTrue($toggle['is_premium']);
        $this->assertSame('Si', DB::connection('wordpress')->table('wp_postmeta')->where('post_id', 101)->value('meta_value'));

        $report = $service->addReport('101', 'Visita', 'Cliente interesado visitó el inmueble.', '2026-08-19', $actor);
        $this->assertGreaterThan(0, $report['id']);
        $reports = $service->reports('101');
        $this->assertSame(1, $reports['metrics']['activities']);
        $this->assertSame(1, $reports['metrics']['visits']);
        $this->assertSame(2, DB::connection('wordpress')->table('wp_jet_cct_historial_del_inmueble')->count());
    }

    public function test_it_rejects_premium_for_an_ineligible_stratum(): void
    {
        $actor = new User(['name' => 'Coordinadora', 'legacy_employee_id' => '77']);
        $actor->id = 9;
        $this->expectException(\DomainException::class);
        (new WordPressHighlightAdminService)->togglePremium('102', true, $actor);
    }

    public function test_it_only_reports_a_real_premium_difference_as_unsynchronized(): void
    {
        DB::connection('wordpress')->table('wp_postmeta')->insert([
            'post_id' => 101,
            'meta_key' => 'inmueble-premium',
            'meta_value' => 'Si',
        ]);

        $item = (new WordPressHighlightAdminService)->premium()['items'][0];

        $this->assertFalse($item['premium_synced']);
        $this->assertSame('WordPress aún Premium', $item['premium_sync_label']);
        $this->assertStringContainsString('catálogo indica Estándar', $item['premium_sync_help']);
    }
}
