<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            // Identidad
            $table->string('code', 32)->unique()->comment('Código público legible (ej: SC-0001)');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('condition', ['new', 'used', 'remodeled', 'under_construction'])->default('used');

            // Ubicación
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('neighborhood_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('address_extra')->nullable()->comment('Apto, torre, interior, etc.');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('show_exact_address')->default(true);

            // Clasificación
            $table->foreignId('property_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_type_id')->constrained()->restrictOnDelete();

            // Precios (NULL si no aplica a la transacción)
            $table->decimal('sale_price', 18, 2)->nullable();
            $table->decimal('rent_price', 18, 2)->nullable();
            $table->decimal('admin_price', 18, 2)->nullable();
            $table->char('currency', 3)->default('COP');
            $table->boolean('price_negotiable')->default(false);

            // Áreas (m²)
            $table->decimal('area_total', 10, 2)->nullable();
            $table->decimal('area_built', 10, 2)->nullable();
            $table->decimal('area_private', 10, 2)->nullable();
            $table->decimal('area_land', 10, 2)->nullable()->comment('Para lotes / fincas');

            // Características físicas
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('half_bathrooms')->nullable();
            $table->unsignedTinyInteger('parking_spaces')->nullable();
            $table->string('parking_type', 50)->nullable()->comment('private, public, covered, uncovered');
            $table->unsignedTinyInteger('floor_number')->nullable();
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->unsignedTinyInteger('stratum')->nullable()->comment('1-6 en Colombia, 0=no aplica');
            $table->boolean('furnished')->default(false);

            // Proyecto / edificio
            $table->string('project_name')->nullable();
            $table->boolean('in_closed_complex')->default(false);

            // Estado y publicación
            $table->enum('status', [
                'draft',          // borrador, no se publica
                'active',         // publicada y visible
                'paused',         // pausada temporalmente
                'reserved',       // reservada (señal/promesa)
                'sold',           // vendida
                'rented',         // arrendada
                'expired',        // caducó
                'archived',       // archivada
            ])->default('draft');
            $table->boolean('featured')->default(false);
            $table->boolean('exclusive')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Asignación
            $table->foreignId('consultant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Contacto público
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_whatsapp', 30)->nullable();
            $table->string('contact_email')->nullable();

            // Estadísticas
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('leads_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Índices compuestos para consultas frecuentes
            $table->index(['status', 'transaction_type_id', 'published_at'], 'idx_status_tx_published');
            $table->index(['city_id', 'status']);
            $table->index(['property_type_id', 'status']);
            $table->index('sale_price');
            $table->index('rent_price');
            $table->index('featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
