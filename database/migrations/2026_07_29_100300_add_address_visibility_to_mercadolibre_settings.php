<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercadolibre_property_settings', function (Blueprint $table) {
            $table->boolean('show_exact_address')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('mercadolibre_property_settings', function (Blueprint $table) {
            $table->dropColumn('show_exact_address');
        });
    }
};
