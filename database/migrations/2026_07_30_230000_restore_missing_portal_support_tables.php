<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('portal_credentials')) {
            Schema::create('portal_credentials', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
                $table->string('account_key', 100)->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                $table->json('data')->nullable()->comment('Datos adicionales cifrados por el modelo');
                $table->timestamps();

                $table->unique(['user_id', 'integration_id']);
                $table->unique(
                    ['integration_id', 'account_key'],
                    'portal_credentials_account_unique'
                );
            });
        }

        if (! Schema::hasTable('ciencuadras_legacy_operations')) {
            Schema::create('ciencuadras_legacy_operations', function (Blueprint $table): void {
                $table->id();
                $table->string('legacy_code', 40)->unique();
                $table->string('source_code', 30)->index();
                $table->string('status', 30)->default('detected')->index();
                $table->uuid('id_request')->nullable()->index();
                $table->json('last_response')->nullable();
                $table->text('last_error')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mercadolibre_notifications')) {
            Schema::create('mercadolibre_notifications', function (Blueprint $table): void {
                $table->id();
                $table->string('notification_id', 100)->unique();
                $table->string('topic', 50);
                $table->string('resource', 500);
                $table->unsignedBigInteger('external_user_id')->nullable();
                $table->string('application_id', 100)->nullable();
                $table->json('payload');
                $table->string('status', 30)->default('pending');
                $table->text('error')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->index(['topic', 'status']);
            });
        }

        if (! Schema::hasTable('portal_reset_events')) {
            Schema::create('portal_reset_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('legacy_employee_id', 80)->nullable();
                $table->string('user_name');
                $table->json('deleted_counts');
                $table->string('backup_file', 500);
                $table->string('backup_checksum', 64);
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Recovery only: rolling back must not remove production support tables.
    }
};
