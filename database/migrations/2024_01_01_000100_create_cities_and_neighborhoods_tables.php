<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->char('dane_code', 8)->unique()->comment('Código DANE del municipio (ej: 70001 = Sincelejo)');
            $table->string('name', 100);
            $table->string('department', 100);
            $table->char('country_code', 2)->default('CO');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('zone', 50)->nullable()->comment('Norte/Sur/Centro/Oriente/Occidente');
            $table->string('postal_code', 10)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neighborhoods');
        Schema::dropIfExists('cities');
    }
};
