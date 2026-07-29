<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciencuadras_legacy_operations', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('ciencuadras_legacy_operations');
    }
};
