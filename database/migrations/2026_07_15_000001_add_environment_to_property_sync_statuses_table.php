<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_sync_statuses', function (Blueprint $table) {
            if (! Schema::hasColumn('property_sync_statuses', 'environment')) {
                $table->string('environment', 30)->default('production')->after('integration_id');
            }
        });

        Schema::table('property_sync_statuses', function (Blueprint $table) {
            $table->dropUnique('property_sync_statuses_property_id_integration_id_unique');
            $table->unique(['property_id', 'integration_id', 'environment'], 'property_sync_environment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('property_sync_statuses', function (Blueprint $table) {
            $table->dropUnique('property_sync_environment_unique');
            $table->unique(['property_id', 'integration_id']);
            $table->dropColumn('environment');
        });
    }
};
