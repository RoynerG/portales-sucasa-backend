<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_sync_statuses', function (Blueprint $table) {
            $table->string('portal_variant', 30)->default('default')->after('environment');
        });

        $this->replaceUnique(
            'property_sync_environment_unique',
            'property_sync_variant_unique',
            ['property_id', 'integration_id', 'environment', 'portal_variant']
        );
    }

    public function down(): void
    {
        $this->replaceUnique(
            'property_sync_variant_unique',
            'property_sync_environment_unique',
            ['property_id', 'integration_id', 'environment']
        );

        Schema::table('property_sync_statuses', function (Blueprint $table) {
            $table->dropColumn('portal_variant');
        });
    }

    private function replaceUnique(string $old, string $new, array $columns): void
    {
        if (DB::getDriverName() === 'mysql') {
            $exists = DB::table('information_schema.statistics')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', 'property_sync_statuses')
                ->where('index_name', $old)
                ->exists();
            if ($exists) {
                DB::statement("ALTER TABLE property_sync_statuses DROP INDEX {$old}");
            }
            DB::statement('ALTER TABLE property_sync_statuses ADD UNIQUE '.$new.' ('.implode(',', $columns).')');

            return;
        }

        Schema::table('property_sync_statuses', function (Blueprint $table) use ($old, $new, $columns) {
            $table->dropUnique($old);
            $table->unique($columns, $new);
        });
    }
};
