<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $integrationId = DB::table('integrations')->where('slug', 'google')->value('id');

        if (! $integrationId) {
            return;
        }

        DB::table('property_sync_statuses')->where('integration_id', $integrationId)->delete();
        DB::table('portal_credentials')->where('integration_id', $integrationId)->delete();
        DB::table('integrations')->where('id', $integrationId)->delete();
    }

    public function down(): void
    {
        //
    }
};
