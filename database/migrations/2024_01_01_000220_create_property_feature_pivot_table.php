<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot property <-> features (muchos a muchos)
        Schema::create('property_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('value', 200)->nullable()->comment('Para features con valor: "2 unidades", "marca Samsung", etc.');
            $table->timestamps();

            $table->unique(['property_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_feature');
    }
};
