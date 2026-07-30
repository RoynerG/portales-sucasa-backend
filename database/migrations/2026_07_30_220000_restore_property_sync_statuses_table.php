<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('property_sync_statuses')) {
            return;
        }

        Schema::create('property_sync_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 30)->default('production');
            $table->string('portal_variant', 30)->default('default');
            $table->enum('sync_status', [
                'not_synced',
                'pending',
                'syncing',
                'synced',
                'error',
                'paused',
                'closed',
            ])->default('not_synced');
            $table->string('external_id')->nullable()->comment('ID del item en el portal');
            $table->string('external_url')->nullable()->comment('URL pública en el portal');
            $table->json('last_response')->nullable()->comment('Respuesta cruda del portal');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(
                ['property_id', 'integration_id', 'environment', 'portal_variant'],
                'property_sync_variant_unique'
            );
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        // This recovery migration must never remove a pre-existing production table.
    }
};
