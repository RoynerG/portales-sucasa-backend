<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imágenes (separadas, no JSON)
        Schema::create('property_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('alt_text', 200)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('file_size')->nullable()->comment('Bytes');
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['property_id', 'order']);
        });

        // Videos
        Schema::create('property_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('provider', 30)->nullable()->comment('youtube, vimeo, direct, mp4');
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('title', 200)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // Planos / documentos
        Schema::create('property_floor_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('label', 100)->nullable()->comment('Plano primer piso, segundo, etc.');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_floor_plans');
        Schema::dropIfExists('property_videos');
        Schema::dropIfExists('property_images');
    }
};
