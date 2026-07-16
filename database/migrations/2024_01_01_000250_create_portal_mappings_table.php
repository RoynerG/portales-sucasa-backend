<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Homologación de IDs externos (portales) por entidad
        // Guarda la correspondencia entre un barrio, feature, etc. propio y el ID que usa cada portal
        Schema::create('portal_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('mappable_type', 100)->comment('Model: neighborhood, feature, property_type, transaction_type');
            $table->unsignedBigInteger('mappable_id');
            $table->string('external_id', 200);
            $table->string('external_name', 200)->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'mappable_type', 'mappable_id'], 'uniq_portal_entity_mapping');
            $table->index(['mappable_type', 'mappable_id']);
            $table->index(['integration_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_mappings');
    }
};
