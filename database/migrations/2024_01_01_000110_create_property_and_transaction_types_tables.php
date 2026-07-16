<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique()->comment('apartamento, casa, lote, finca, ...');
            $table->string('name', 100);
            $table->string('icon', 50)->nullable()->comment('Clase de icono FA: fa-house, fa-building...');
            $table->string('color', 7)->nullable()->comment('Hex: #ef4444');
            $table->boolean('is_building')->default(false)->comment('Tiene unidades internas (apartamentos, oficinas)');
            $table->boolean('is_land')->default(false)->comment('Es terreno (lote, finca)');
            $table->boolean('is_commercial')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('transaction_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique()->comment('sale, rent, sale_rent');
            $table->string('name', 50);
            $table->boolean('has_sale_price')->default(true);
            $table->boolean('has_rent_price')->default(true);
            $table->boolean('has_admin_price')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_types');
        Schema::dropIfExists('property_types');
    }
};
