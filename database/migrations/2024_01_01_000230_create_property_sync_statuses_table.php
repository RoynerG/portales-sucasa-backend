<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sincronización por propiedad por portal
        Schema::create('property_sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->enum('sync_status', [
                'not_synced',   // nunca se ha enviado
                'pending',      // en cola
                'syncing',      // en proceso
                'synced',       // publicado OK
                'error',        // falló
                'paused',       // pausado en el portal
            ])->default('not_synced');
            $table->string('external_id')->nullable()->comment('ID del item en el portal');
            $table->string('external_url')->nullable()->comment('URL pública en el portal');
            $table->json('last_response')->nullable()->comment('Respuesta cruda del portal');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'integration_id']);
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_sync_statuses');
    }
};
