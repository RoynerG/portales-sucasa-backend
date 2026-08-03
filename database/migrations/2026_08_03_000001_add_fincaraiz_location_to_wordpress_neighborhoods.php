<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('wordpress');
        if (! $schema->hasTable('wp_jet_cct_barrios')) {
            return;
        }

        if (! $schema->hasColumn('wp_jet_cct_barrios', 'fincaraiz_location_id')) {
            $schema->table('wp_jet_cct_barrios', function (Blueprint $table): void {
                $table->char('fincaraiz_location_id', 36)->nullable();
            });
        }
        if (! $schema->hasColumn('wp_jet_cct_barrios', 'fincaraiz_location_name')) {
            $schema->table('wp_jet_cct_barrios', function (Blueprint $table): void {
                $table->string('fincaraiz_location_name', 200)->nullable();
            });
        }
        if (! $schema->hasColumn('wp_jet_cct_barrios', 'fincaraiz_location_type')) {
            $schema->table('wp_jet_cct_barrios', function (Blueprint $table): void {
                $table->string('fincaraiz_location_type', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        // External WordPress data is intentionally preserved on rollback.
    }
};
