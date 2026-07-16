<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('position', 100)->nullable()->comment('Cargo: Asesor senior, Junior, Director...');
            $table->string('department', 100)->nullable();
            $table->string('license_number', 50)->nullable()->comment('Matrícula inmobiliaria');
            $table->unsignedInteger('properties_limit')->default(30);
            $table->unsignedInteger('featured_limit')->default(5);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultants');
    }
};
