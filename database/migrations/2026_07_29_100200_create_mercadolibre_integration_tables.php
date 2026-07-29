<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercadolibre_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('property_type_slug', 80);
            $table->string('operation', 20);
            $table->string('category_id', 50);
            $table->json('category_path')->nullable();
            $table->json('settings')->nullable();
            $table->json('attributes')->nullable();
            $table->boolean('is_leaf')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['property_type_slug', 'operation'], 'ml_category_mapping_unique');
        });

        Schema::create('mercadolibre_location_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_department', 150)->default('');
            $table->string('source_city', 150);
            $table->string('source_neighborhood', 180)->default('');
            $table->string('state_id', 100);
            $table->string('state_name', 150);
            $table->string('city_id', 100);
            $table->string('city_name', 150);
            $table->string('neighborhood_id', 100)->nullable();
            $table->string('neighborhood_name', 180)->nullable();
            $table->timestamps();
            $table->unique(
                ['source_department', 'source_city', 'source_neighborhood'],
                'ml_location_mapping_unique'
            );
        });

        Schema::create('mercadolibre_property_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 20);
            $table->string('listing_type_id', 30)->default('silver');
            $table->string('category_id', 50)->nullable();
            $table->json('attributes')->nullable();
            $table->json('location')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'operation'], 'ml_property_setting_unique');
        });

        Schema::create('mercadolibre_notifications', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('mercadolibre_notifications');
        Schema::dropIfExists('mercadolibre_property_settings');
        Schema::dropIfExists('mercadolibre_location_mappings');
        Schema::dropIfExists('mercadolibre_category_mappings');
    }
};
