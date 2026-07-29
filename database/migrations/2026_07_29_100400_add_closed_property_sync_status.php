<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE property_sync_statuses MODIFY sync_status ENUM('not_synced','pending','syncing','synced','error','paused','closed') NOT NULL DEFAULT 'not_synced'");
        }
    }

    public function down(): void
    {
        DB::table('property_sync_statuses')->where('sync_status', 'closed')->update(['sync_status' => 'not_synced']);
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE property_sync_statuses MODIFY sync_status ENUM('not_synced','pending','syncing','synced','error','paused') NOT NULL DEFAULT 'not_synced'");
        }
    }
};
