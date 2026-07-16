<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de categorías de cada portal
        Schema::create('portal_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('name', 200);
            $table->string('parent_external_id', 100)->nullable();
            $table->unsignedInteger('level')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_categories');
    }
};
