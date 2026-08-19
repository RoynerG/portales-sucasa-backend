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
            $table->string('id_propietario')->nullable();
            $table->string('propietario')->nullable();
            $table->unsignedBigInteger('fecha_destacado')->nullable();
            $table->string('destacado')->nullable();
            $table->string('marcado_destacado')->nullable();
            $table->string('oportunidad')->nullable();
            $table->string('negociable')->nullable();
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
            $table->unsignedBigInteger('cct_author_id')->nullable();
            $table->dateTime('cct_created')->nullable();
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
            $table->string('solicitado_por_id')->nullable();
            $table->string('solicitado_por_nombre')->nullable();
            $table->string('razon')->nullable();
            $table->string('oportunidad')->nullable();
            $table->string('negociable')->nullable();
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

        Schema::connection('wordpress')->create('wp_jet_cct_funcionarios', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('id_empleado');
            $table->string('nombre');
            $table->string('rol')->nullable();
            $table->string('gestion')->nullable();
            $table->string('activo')->default('Si');
            $table->string('id_cargo');
            $table->string('correo')->nullable();
            foreach (array_keys(WordPressHighlightService::MARKETS) as $market) {
                $table->unsignedInteger($market)->default(0);
            }
        });

        Schema::connection('wordpress')->create('wp_jet_cct_confi_sistema', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('funcion');
            $table->string('valor');
            $table->string('imagen')->nullable();
        });

        Schema::connection('wordpress')->create('wp_jet_cct_propietarios', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('id_propietario');
            $table->string('nombre')->nullable();
            $table->string('nombre_juridico')->nullable();
            $table->string('correo')->nullable();
        });

        Schema::connection('wordpress')->create('skc_notification_queue', function (Blueprint $table): void {
            $table->id();
            foreach (['project_code', 'source_module', 'channel', 'provider', 'destination', 'destination_name', 'subject', 'message_text', 'message_html', 'template_name', 'template_language', 'payload_json', 'meta_json', 'status', 'dedupe_key', 'locked_by', 'last_error', 'created_by'] as $column) {
                $table->text($column)->nullable();
            }
            foreach (['priority', 'attempts', 'max_attempts'] as $column) {
                $table->integer($column)->nullable();
            }
            foreach (['scheduled_at', 'next_attempt_at', 'locked_at', 'last_attempt_at', 'sent_at', 'created_at', 'updated_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
        });

        $this->seedHighlights();
    }

    public function test_it_lists_active_highlights_with_market_and_people_context(): void
    {
        $result = (new WordPressHighlightService)->index();

        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(2, $result['summary']['active']);
        $this->assertSame(2, $result['summary']['consultants']);
        $this->assertSame(2, $result['summary']['pending']);
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
        $this->assertStringContainsString('No se eliminó el historial', $history->observacion);
    }

    public function test_it_reports_and_updates_employee_quotas_without_exceeding_system_limits(): void
    {
        $service = new WordPressHighlightService;
        $result = $service->quotas();

        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(12, $result['summary']['limit']);
        $this->assertSame(5, $result['summary']['assigned']);
        $this->assertSame(2, $result['summary']['used']);
        $this->assertSame(2, $result['summary']['pending']);
        $this->assertSame(1, $result['summary']['overcommitted']);
        $this->assertSame(2, $result['summary']['available']);

        $employee = collect($result['items'])->firstWhere('employee_id', '10');
        $this->assertSame(3, $employee['markets']['mercado_libre_destacados']['assigned']);
        $this->assertSame(1, $employee['markets']['mercado_libre_destacados']['used']);
        $this->assertSame(1, $employee['markets']['mercado_libre_destacados']['available']);

        $updated = $service->updateQuotas((string) $employee['id'], ['mercado_libre_destacados' => 4]);
        $this->assertSame(4, $updated['quotas']['mercado_libre_destacados']);
        $this->assertSame(4, DB::connection('wordpress')->table('wp_jet_cct_funcionarios')->where('_ID', $employee['id'])->value('mercado_libre_destacados'));

        $this->expectException(\DomainException::class);
        $service->updateQuotas((string) $employee['id'], ['mercado_libre_destacados' => 6]);
    }

    public function test_it_updates_system_limits_without_dropping_below_assigned_quotas(): void
    {
        $service = new WordPressHighlightService;
        $result = $service->updateQuotaLimits(['mercado_libre_destacados' => 6]);

        $this->assertSame(6, $result['limits']['mercado_libre_destacados']);
        $this->assertSame('6', DB::connection('wordpress')->table('wp_jet_cct_confi_sistema')->where('funcion', 'mercado_libre_destacados')->value('valor'));

        $this->expectException(\DomainException::class);
        $service->updateQuotaLimits(['mercado_libre_destacados' => 2]);
    }

    public function test_it_attributes_an_active_quota_to_the_latest_highlight_employee(): void
    {
        DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->where('codigo', '100')->update([
            'id_funcionario' => '20',
            'funcionario' => 'Asesor Dos',
        ]);

        $result = (new WordPressHighlightService)->quotas();
        $employeeTen = collect($result['items'])->firstWhere('employee_id', '10');
        $employeeTwenty = collect($result['items'])->firstWhere('employee_id', '20');

        $this->assertSame(1, $employeeTen['markets']['mercado_libre_destacados']['used']);
        $this->assertSame(0, $employeeTwenty['markets']['mercado_libre_destacados']['used']);
        $this->assertSame(1, $employeeTwenty['markets']['proppit_promocionados']['used']);
    }

    public function test_it_lists_and_completes_a_pending_highlight_request(): void
    {
        $service = new WordPressHighlightService;
        $pending = $service->pendingRequests();
        $request = collect($pending['items'])->firstWhere('code', '300');

        $this->assertSame(2, $pending['summary']['total']);
        $this->assertSame('Mercado Libre', $request['market_label']);
        $this->assertSame('Ana Asesora', $request['requested_by']);

        $actor = new User(['name' => 'Coordinador', 'legacy_employee_id' => '77']);
        $actor->id = 9;
        $result = $service->completeRequest($request['id'], $actor);

        $this->assertSame('300', $result['code']);
        $property = DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->where('codigo', '300')->first();
        $this->assertSame('Si', $property->destacado);
        $this->assertSame('Si', $property->mercado_libre_destacado);
        $this->assertSame('No', $property->marcado_destacado);
        $this->assertSame('destacado', DB::connection('wordpress')->table('wp_skc_destacado_solicitudes')->where('id', $request['id'])->value('estado'));
        $this->assertSame('Coordinador', DB::connection('wordpress')->table('wp_skc_destacado_solicitudes')->where('id', $request['id'])->value('completado_por_nombre'));
    }

    public function test_it_queues_owner_and_employee_emails_after_confirming_a_highlight(): void
    {
        DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->where('codigo', '300')->update([
            'id_propietario' => '900',
            'propietario' => 'Propietaria Prueba',
        ]);
        DB::connection('wordpress')->table('wp_jet_cct_propietarios')->insert([
            'id_propietario' => '900',
            'nombre' => 'Propietaria Prueba',
            'correo' => 'propietaria@example.com',
        ]);
        DB::connection('wordpress')->table('wp_jet_cct_funcionarios')->where('id_empleado', '10')->update(['correo' => 'asesora@example.com']);
        $requestId = DB::connection('wordpress')->table('wp_skc_destacado_solicitudes')->where('codigo_inmueble', '300')->value('id');
        $actor = new User(['name' => 'Coordinador', 'legacy_employee_id' => '77']);
        $actor->id = 9;

        $result = (new WordPressHighlightService)->completeRequest((string) $requestId, $actor);

        $this->assertSame(2, $result['notifications']['queued']);
        $this->assertSame(2, DB::connection('wordpress')->table('skc_notification_queue')->count());
        $this->assertSame(['asesora@example.com', 'propietaria@example.com'], DB::connection('wordpress')->table('skc_notification_queue')->orderBy('destination')->pluck('destination')->all());
        $this->assertSame(['portales-sucasa'], DB::connection('wordpress')->table('skc_notification_queue')->distinct()->pluck('project_code')->all());
        $this->assertSame(2, DB::connection('wordpress')->table('skc_notification_queue')->where('status', 'pending')->count());
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
                'solicitado_por_id' => '10',
                'solicitado_por_nombre' => 'Ana Asesora',
                'razon' => 'Precio',
                'oportunidad' => 'Si',
                'negociable' => 'No',
                'completado_por_id' => '6',
                'completado_por_nombre' => 'Coordinador',
                'requested_at' => '2024-07-01 10:00:00',
                'completed_at' => '2024-07-01 11:00:00',
            ],
            [
                'codigo_inmueble' => '200',
                'portal' => 'finca_raiz_silver',
                'estado' => 'pendiente',
                'solicitado_por_id' => '20',
                'solicitado_por_nombre' => 'Asesor Dos',
                'razon' => 'Oportunidad',
                'oportunidad' => 'Si',
                'negociable' => 'Si',
                'completado_por_id' => null,
                'completado_por_nombre' => null,
                'requested_at' => '2024-07-02 10:00:00',
                'completed_at' => null,
            ],
            [
                'codigo_inmueble' => '300',
                'portal' => 'mercado_libre_destacados',
                'estado' => 'pendiente',
                'solicitado_por_id' => '10',
                'solicitado_por_nombre' => 'Ana Asesora',
                'razon' => 'Ubicación',
                'oportunidad' => 'Si',
                'negociable' => 'Si',
                'completado_por_id' => null,
                'completado_por_nombre' => null,
                'requested_at' => '2024-07-03 10:00:00',
                'completed_at' => null,
            ],
        ]);

        $employeeDefaults = array_fill_keys(array_keys(WordPressHighlightService::MARKETS), 0);
        DB::connection('wordpress')->table('wp_jet_cct_funcionarios')->insert([
            [...$employeeDefaults, '_ID' => 1, 'id_empleado' => '10', 'nombre' => 'Asesora Uno', 'rol' => 'Asesor', 'gestion' => 'Ventas', 'activo' => 'Si', 'id_cargo' => '9', 'mercado_libre_destacados' => 3],
            [...$employeeDefaults, '_ID' => 2, 'id_empleado' => '20', 'nombre' => 'Asesor Dos', 'rol' => 'Asesor', 'gestion' => 'Arriendos', 'activo' => 'Si', 'id_cargo' => '10', 'proppit_promocionados' => 2],
            [...$employeeDefaults, '_ID' => 3, 'id_empleado' => '30', 'nombre' => 'Funcionario oculto', 'rol' => 'Otro', 'gestion' => 'Otra', 'activo' => 'Si', 'id_cargo' => '99'],
        ]);

        DB::connection('wordpress')->table('wp_jet_cct_confi_sistema')->insert([
            ['funcion' => 'mercado_libre_destacados', 'valor' => '5'],
            ['funcion' => 'proppit_promocionados', 'valor' => '4'],
            ['funcion' => 'ciencuadras_ascendidos', 'valor' => '3'],
        ]);
    }
}
