<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureHighlightAdminAccess;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portal_reset.allowed_cargo_ids', [11, 12, 13, 14]);
        config()->set('portal_reset.allowed_role_keywords', ['gerencia', 'desarrollo']);
        config()->set('highlight_admin.allowed_cargo_ids', [1, 6, 11, 12, 13, 14]);
        config()->set('highlight_admin.allowed_role_keywords', ['gerencia', 'desarrollo']);
        config()->set('portal_reset.confirmation_phrase', 'REINICIAR PORTALES');
        Storage::fake('local');
        $this->createTables();
        $this->seedPortalHistory();
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('portal-resets');

        parent::tearDown();
    }

    public function test_only_management_and_development_can_open_reset_settings(): void
    {
        Sanctum::actingAs($this->userWithCargo(9));

        $this->getJson('/api/portals/settings/reset-preview')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Esta configuración está disponible únicamente para Gerencias y Desarrollo.'
            );
    }

    public function test_all_management_cargos_can_open_reset_settings(): void
    {
        foreach ([11, 12, 14] as $cargoId) {
            Sanctum::actingAs($this->userWithCargo($cargoId));

            $this->getJson('/api/portals/settings/reset-preview')
                ->assertOk();
        }
    }

    public function test_commercial_highlight_admin_does_not_receive_portal_reset_access(): void
    {
        $user = $this->userWithCargo(6);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('Datos.permissions', ['highlight_admin']);

        $this->getJson('/api/portals/settings/reset-preview')
            ->assertForbidden();

        $request = Request::create('/api/properties/highlight-history');
        $request->setUserResolver(fn (): User => $user);
        $response = app(EnsureHighlightAdminAccess::class)->handle(
            $request,
            fn (Request $request) => response()->json(['authorized' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_future_management_cargo_is_allowed_by_its_role(): void
    {
        Sanctum::actingAs($this->userWithCargo(99, 'Gerencia Regional'));

        $this->getJson('/api/portals/settings/reset-preview')
            ->assertOk();
    }

    public function test_reset_requires_the_exact_confirmation_phrase(): void
    {
        Sanctum::actingAs($this->userWithCargo(13));

        $this->postJson('/api/portals/settings/reset', [
            'confirmation' => 'reiniciar',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->assertDatabaseCount('property_sync_statuses', 1);
    }

    public function test_authorized_user_can_reset_only_portal_history(): void
    {
        Sanctum::actingAs($this->userWithCargo(11));

        $this->getJson('/api/portals/settings/reset-preview')
            ->assertOk()
            ->assertJsonPath('Datos.counts.sync_records', 1)
            ->assertJsonPath('Datos.counts.external_ids', 1)
            ->assertJsonPath('Datos.records_to_delete', 4);

        $this->postJson('/api/portals/settings/reset', [
            'confirmation' => 'REINICIAR PORTALES',
        ])->assertOk()
            ->assertJsonPath('Datos.deleted.sync_records', 1)
            ->assertJsonPath('Datos.deleted.mercadolibre_notifications', 1)
            ->assertJsonPath('Datos.deleted.ciencuadras_operations', 1)
            ->assertJsonPath('Datos.deleted.portal_cache', 1);

        $this->assertDatabaseCount('property_sync_statuses', 0);
        $this->assertDatabaseCount('mercadolibre_notifications', 0);
        $this->assertDatabaseCount('ciencuadras_legacy_operations', 0);
        $this->assertDatabaseCount('cache', 1);
        $this->assertDatabaseCount('integrations', 1);
        $this->assertDatabaseCount('portal_reset_events', 1);

        $files = Storage::disk('local')->allFiles('portal-resets');
        $this->assertCount(1, $files);
        Storage::disk('local')->assertExists($files[0]);
    }

    private function userWithCargo(int $cargoId, ?string $role = null): User
    {
        $user = new User([
            'name' => 'Usuario de prueba',
            'email' => "cargo{$cargoId}@sucasa.test",
            'role' => 'viewer',
            'active' => true,
            'legacy_source' => 'test',
            'legacy_employee_id' => (string) $cargoId,
            'preferences' => [
                'id_cargo' => $cargoId,
                'rol' => $role ?? match ($cargoId) {
                    11, 12, 14 => 'Gerencia',
                    13 => 'Desarrollo',
                    default => 'Comercial',
                },
            ],
        ]);
        $user->id = $cargoId;
        $user->exists = true;

        return $user;
    }

    private function seedPortalHistory(): void
    {
        DB::table('integrations')->insert([
            'id' => 1,
            'name' => 'Ciencuadras',
            'slug' => 'ciencuadras',
        ]);
        DB::table('property_sync_statuses')->insert([
            'integration_id' => 1,
            'sync_status' => 'error',
            'external_id' => '22130-1001',
            'external_url' => 'https://portal.test/1001',
        ]);
        DB::table('mercadolibre_notifications')->insert([
            'notification_id' => 'notification-1',
        ]);
        DB::table('ciencuadras_legacy_operations')->insert([
            'legacy_code' => '22130-P1001',
        ]);
        DB::table('cache')->insert([
            ['key' => 'ciencuadras.inventory', 'value' => 'cached', 'expiration' => 0],
            ['key' => 'branding', 'value' => 'preserved', 'expiration' => 0],
        ]);
    }

    private function createTables(): void
    {
        foreach ([
            'portal_reset_events',
            'property_sync_statuses',
            'mercadolibre_notifications',
            'ciencuadras_legacy_operations',
            'cache',
            'integrations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });
        Schema::create('property_sync_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sync_status');
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
        });
        Schema::create('mercadolibre_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('notification_id');
        });
        Schema::create('ciencuadras_legacy_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_code');
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('portal_reset_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('legacy_employee_id')->nullable();
            $table->string('user_name');
            $table->json('deleted_counts');
            $table->string('backup_file');
            $table->string('backup_checksum');
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }
}
