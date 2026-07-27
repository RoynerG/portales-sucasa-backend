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
            if (! Schema::hasColumn('property_sync_statuses', 'environment')) {
                $table->string('environment', 30)->default('production')->after('integration_id');
            }
        });

        if (! $this->indexExists('property_sync_environment_unique')) {
            $this->dropForeignIfExists('property_sync_statuses_property_id_foreign');
            $this->dropForeignIfExists('property_sync_statuses_integration_id_foreign');

            $this->dropIndexIfExists('property_sync_statuses_property_id_integration_id_unique');

            $this->addIndexIfMissing('property_sync_statuses_property_id_index', 'property_id');
            $this->addIndexIfMissing('property_sync_statuses_integration_id_index', 'integration_id');

            DB::statement('ALTER TABLE property_sync_statuses ADD UNIQUE property_sync_environment_unique (property_id, integration_id, environment)');

            $this->addForeignIfMissing('property_sync_statuses_property_id_foreign', 'property_id', 'properties');
            $this->addForeignIfMissing('property_sync_statuses_integration_id_foreign', 'integration_id', 'integrations');
        }
    }

    public function down(): void
    {
        $this->dropForeignIfExists('property_sync_statuses_property_id_foreign');
        $this->dropForeignIfExists('property_sync_statuses_integration_id_foreign');

        $this->dropIndexIfExists('property_sync_environment_unique');

        if (! $this->indexExists('property_sync_statuses_property_id_integration_id_unique')) {
            DB::statement('ALTER TABLE property_sync_statuses ADD UNIQUE property_sync_statuses_property_id_integration_id_unique (property_id, integration_id)');
        }

        $this->addForeignIfMissing('property_sync_statuses_property_id_foreign', 'property_id', 'properties');
        $this->addForeignIfMissing('property_sync_statuses_integration_id_foreign', 'integration_id', 'integrations');

        Schema::table('property_sync_statuses', function (Blueprint $table) {
            if (Schema::hasColumn('property_sync_statuses', 'environment')) {
                $table->dropColumn('environment');
            }
        });
    }

    private function indexExists(string $index): bool
    {
        return (bool) DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', 'property_sync_statuses')
            ->where('index_name', $index)
            ->exists();
    }

    private function foreignExists(string $foreign): bool
    {
        return (bool) DB::table('information_schema.table_constraints')
            ->whereRaw('constraint_schema = DATABASE()')
            ->where('table_name', 'property_sync_statuses')
            ->where('constraint_name', $foreign)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    private function dropForeignIfExists(string $foreign): void
    {
        if ($this->foreignExists($foreign)) {
            DB::statement("ALTER TABLE property_sync_statuses DROP FOREIGN KEY {$foreign}");
        }
    }

    private function dropIndexIfExists(string $index): void
    {
        if ($this->indexExists($index)) {
            DB::statement("ALTER TABLE property_sync_statuses DROP INDEX {$index}");
        }
    }

    private function addIndexIfMissing(string $index, string $column): void
    {
        if (! $this->indexExists($index)) {
            DB::statement("ALTER TABLE property_sync_statuses ADD INDEX {$index} ({$column})");
        }
    }

    private function addForeignIfMissing(string $foreign, string $column, string $referencesTable): void
    {
        if (! $this->foreignExists($foreign)) {
            DB::statement(
                "ALTER TABLE property_sync_statuses ADD CONSTRAINT {$foreign} FOREIGN KEY ({$column}) REFERENCES {$referencesTable} (id) ON DELETE CASCADE"
            );
        }
    }
};
