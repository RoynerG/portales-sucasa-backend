<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\WordPressHighlightService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WordPressHighlightServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->string('cct_status')->default('publish');
            $table->string('codigo');
            $table->string('estado')->default('Publico');
            $table->string('tipo_inmueble')->nullable();
            $table->string('tipo_negocio')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('barrio')->nullable();
            $table->string('direccion')->nullable();
            $table->string('id_funcionario')->nullable();
            $table->string('funcionario')->nullable();
            $table->unsignedBigInteger('fecha_destacado')->nullable();
            $table->string('destacado')->nullable();
            $table->string('marcado_destacado')->nullable();
            $table->string('mercado_libre_destacado')->nullable();
            $table->string('proppit_promocionado')->nullable();
            $table->string('ciencuadras_ascendido')->nullable();
            $table->string('ciencuadras_destacado')->nullable();
            $table->string('finca_raiz_silver')->nullable();
            $table->string('finca_raiz_gold')->nullable();
            $table->string('finca_raiz_black')->nullable();
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
            $table->string('mercado_libre_destacados')->nullable();
            $table->string('proppit_promocionados')->nullable();
            $table->string('ciencuadras_ascendidos')->nullable();
            $table->string('ciencuadras_destacados')->nullable();
            $table->string('finca_raiz_silver')->nullable();
            $table->string('finca_raiz_gold')->nullable();
            $table->string('finca_raiz_black')->nullable();
        });

        Schema::connection('wordpress')->create('wp_skc_destacado_solicitudes', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo_inmueble');
            $table->string('portal');
            $table->string('estado');
            $table->string('completado_por_id')->nullable();
            $table->string('completado_por_nombre')->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('completed_at')->nullable();
        });

        Schema::connection('wordpress')->create('wp_jet_cct_historial_del_inmueble', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->unsignedBigInteger('fecha')->nullable();
            $table->string('id_inmueble');
            $table->string('id_empleado')->nullable();
            $table->string('funcionario')->nullable();
            $table->string('tipo_reporte')->nullable();
            $table->text('observacion')->nullable();
            $table->unsignedBigInteger('cct_author_id')->nullable();
            $table->dateTime('cct_created')->nullable();
            $table->dateTime('cct_modified')->nullable();
        });

        $this->seedHighlights();
    }

    public function test_it_lists_active_highlights_with_market_and_people_context(): void
    {
        $result = (new WordPressHighlightService)->index();

        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(2, $result['summary']['active']);
        $this->assertSame(2, $result['summary']['consultants']);
        $this->assertSame(1, $result['summary']['pending']);
        $this->assertSame(1, $result['summary']['markets']['mercado_libre_destacados']);
        $this->assertSame(1, $result['summary']['markets']['proppit_promocionados']);

        $first = collect($result['items'])->firstWhere('code', '100');
        $this->assertSame('Ana Asesora', $first['highlighted_by']);
        $this->assertSame('Coordinador', $first['completed_by']);
        $this->assertSame('Mercado Libre', $first['markets'][0]['label']);

        $filtered = (new WordPressHighlightService)->index(['mercado' => 'proppit_promocionados']);
        $this->assertSame(['200'], array_column($filtered['items'], 'code'));
    }

    public function test_it_releases_every_market_and_registers_the_actor(): void
    {
        $actor = new User([
            'name' => 'Royner Guardo',
            'legacy_employee_id' => '77',
        ]);
        $actor->id = 9;

        $result = (new WordPressHighlightService)->release('100', $actor);

        $this->assertSame('100', $result['code']);
        $this->assertSame('Mercado Libre', $result['released_markets'][0]['label']);

        $property = DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->where('codigo', '100')->first();
        $this->assertSame('No', $property->destacado);
        $this->assertSame('No', $property->mercado_libre_destacado);
        $this->assertNull($property->fecha_destacado);

        $history = DB::connection('wordpress')->table('wp_jet_cct_historial_del_inmueble')->latest('_ID')->first();
        $this->assertSame('77', $history->id_empleado);
        $this->assertSame('Royner Guardo', $history->funcionario);
        $this->assertStringContainsString('panel de portales', $history->observacion);
    }

    private function seedHighlights(): void
    {
        $defaults = [
            'cct_status' => 'publish',
            'estado' => 'Publico',
            'tipo_inmueble' => 'Apartamento',
            'tipo_negocio' => 'Venta',
            'ciudad' => 'Cartagena',
            'barrio' => 'Manga',
            'direccion' => 'Calle 1',
            'marcado_destacado' => 'No',
            'mercado_libre_destacado' => 'No',
            'proppit_promocionado' => 'No',
            'ciencuadras_ascendido' => 'No',
            'ciencuadras_destacado' => 'No',
            'finca_raiz_silver' => 'No',
            'finca_raiz_gold' => 'No',
            'finca_raiz_black' => 'No',
        ];

        DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->insert([
            [...$defaults, 'codigo' => '100', 'id_funcionario' => '10', 'funcionario' => 'Asesora Uno', 'fecha_destacado' => 1_720_000_000, 'destacado' => 'Si', 'mercado_libre_destacado' => 'Si'],
            [...$defaults, 'codigo' => '200', 'id_funcionario' => '20', 'funcionario' => 'Asesor Dos', 'fecha_destacado' => 1_721_000_000, 'destacado' => 'Si', 'proppit_promocionado' => 'Si'],
            [...$defaults, 'codigo' => '300', 'id_funcionario' => '30', 'funcionario' => 'Asesor Tres', 'fecha_destacado' => null, 'destacado' => 'No'],
        ]);

        DB::connection('wordpress')->table('wp_jet_cct_inmuebles_destacados')->insert([
            'id_inmueble' => '100',
            'fecha' => 1_720_000_000,
            'id_empleado' => '10',
            'empleado' => 'Ana Asesora',
            'observacion_destacado' => 'Inmueble con oportunidad comercial',
            'veces_destacado' => '2',
            'oportunidad' => 'Si',
            'negociable' => 'No',
            'mercado_libre_destacados' => 'Si',
            'proppit_promocionados' => 'No',
            'ciencuadras_ascendidos' => 'No',
            'ciencuadras_destacados' => 'No',
            'finca_raiz_silver' => 'No',
            'finca_raiz_gold' => 'No',
            'finca_raiz_black' => 'No',
        ]);

        DB::connection('wordpress')->table('wp_skc_destacado_solicitudes')->insert([
            [
                'codigo_inmueble' => '100',
                'portal' => 'mercado_libre_destacados',
                'estado' => 'destacado',
                'completado_por_id' => '6',
                'completado_por_nombre' => 'Coordinador',
                'requested_at' => '2024-07-01 10:00:00',
                'completed_at' => '2024-07-01 11:00:00',
            ],
            [
                'codigo_inmueble' => '200',
                'portal' => 'finca_raiz_silver',
                'estado' => 'pendiente',
                'completado_por_id' => null,
                'completado_por_nombre' => null,
                'requested_at' => '2024-07-02 10:00:00',
                'completed_at' => null,
            ],
        ]);
    }
}
