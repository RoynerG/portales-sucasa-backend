<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('slug', 50)->unique();
            $table->string('api_class', 100)->nullable()->comment('FQCN del Service: App\\Services\\Portals\\MercadoLibreClient');
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->string('website_url')->nullable();
            $table->json('config_schema')->nullable()->comment('Campos requeridos por el portal (api_key, client_id, etc.)');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('portal_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('data')->nullable()->comment('Email, password, api_key, client_id, etc.');
            $table->timestamps();

            $table->unique(['user_id', 'integration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_credentials');
        Schema::dropIfExists('integrations');
    }
};
