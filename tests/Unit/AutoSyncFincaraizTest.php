<?php

namespace Tests\Unit;

use App\Console\Commands\AutoSyncFincaraiz;
use App\Models\PortalCredential;
use App\Models\PropertySyncStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use stdClass;
use Tests\TestCase;

class AutoSyncFincaraizTest extends TestCase
{
    public function test_it_uses_panel_automation_settings_with_an_environment_api_key(): void
    {
        $command = new class extends AutoSyncFincaraiz
        {
            public function exposedPreferredCredential(Collection $credentials): ?PortalCredential
            {
                return $this->preferredCredential($credentials);
            }
        };
        $environmentBacked = new PortalCredential([
            'access_token' => null,
            'data' => ['auto_sync' => true, 'auto_sync_limit' => 20],
        ]);
        $panelBackedButDisabled = new PortalCredential([
            'access_token' => 'panel-key',
            'data' => ['auto_sync' => false],
        ]);

        $selected = $command->exposedPreferredCredential(collect([
            $panelBackedButDisabled,
            $environmentBacked,
        ]));

        $this->assertSame($environmentBacked, $selected);
        $this->assertNull($selected->access_token);
        $this->assertTrue((bool) data_get($selected->data, 'auto_sync'));
    }

    public function test_it_decides_catalog_actions_without_republishing_existing_listings(): void
    {
        $command = new class extends AutoSyncFincaraiz
        {
            public function exposedDecision(stdClass $row, ?PropertySyncStatus $sync): ?string
            {
                return $this->decision($row, $sync);
            }
        };
        $public = (object) ['estado' => 'Publico', 'fecha_actualizacion' => '2026-08-03 12:00:00', 'cct_modified' => null];
        $private = (object) ['estado' => 'No publicar', 'fecha_actualizacion' => null, 'cct_modified' => null];

        $this->assertSame('publish', $command->exposedDecision($public, null));
        $this->assertSame('activate', $command->exposedDecision($public, new PropertySyncStatus([
            'sync_status' => 'paused',
            'external_id' => '11111111-1111-4111-8111-111111111111',
        ])));
        $this->assertSame('update', $command->exposedDecision($public, new PropertySyncStatus([
            'sync_status' => 'synced',
            'external_id' => '11111111-1111-4111-8111-111111111111',
            'last_synced_at' => Carbon::parse('2026-08-03 11:00:00'),
        ])));
        $this->assertSame('pause', $command->exposedDecision($private, new PropertySyncStatus([
            'sync_status' => 'synced',
            'external_id' => '11111111-1111-4111-8111-111111111111',
        ])));
        $this->assertNull($command->exposedDecision($public, new PropertySyncStatus([
            'sync_status' => 'pending',
        ])));
    }
}
