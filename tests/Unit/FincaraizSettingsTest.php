<?php

namespace Tests\Unit;

use App\Http\Controllers\Portal\FincaraizController;
use App\Models\PortalCredential;
use App\Models\User;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\FincaraizPropertyMapper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FincaraizSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('portal_credentials');
        Schema::dropIfExists('integrations');
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
        });
        Schema::create('portal_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('account_key')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
        DB::table('integrations')->insert(['id' => 1, 'slug' => 'fincaraiz']);
    }

    public function test_panel_settings_encrypt_the_api_key_and_return_only_safe_fields(): void
    {
        $user = new User;
        $user->id = 15;
        $user->exists = true;
        $request = Request::create('/api/portals/fincaraiz/settings', 'PATCH', [
            'api_key' => 'qa-secret-key',
            'client_id' => 'df03d199-be5c-4c5c-98f6-849361cb7fae',
            'client_agent' => 1234,
            'contact_email' => 'asesor@example.com',
            'contact_phone' => '3001234567',
            'contact_whatsapp' => '3001234567',
            'show_exact_address' => false,
            'dual_offer' => 'rent',
        ]);
        $request->setUserResolver(fn () => $user);

        $controller = new FincaraizController(
            $this->createMock(FincaraizClient::class),
            $this->createMock(FincaraizPropertyMapper::class)
        );
        $response = $controller->saveSettings($request);
        $credential = PortalCredential::firstOrFail();
        $data = $response->getData(true)['Datos'];

        $this->assertSame('qa-secret-key', $credential->access_token);
        $this->assertNotSame('qa-secret-key', DB::table('portal_credentials')->value('access_token'));
        $this->assertSame('panel', $data['api_key_source']);
        $this->assertTrue($data['configured']);
        $this->assertArrayNotHasKey('api_key', $data);
        $this->assertSame(1234, $data['client_agent']);
        $this->assertSame('rent', $data['dual_offer']);
    }
}
