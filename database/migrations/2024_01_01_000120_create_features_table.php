<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->enum('group', ['internal', 'external', 'surrounding', 'rule'])
                ->index()
                ->comment('internal=dentro, external=conjunto, surrounding=alrededores, rule=normas');
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->string('icon', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['group', 'slug']);
        });

        Schema::create('property_type_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('order')->default(0);

            $table->unique(['property_type_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_type_features');
        Schema::dropIfExists('features');
    }
};
