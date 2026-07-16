<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('legacy_source')->nullable()->after('active');
            $table->string('legacy_employee_id')->nullable()->after('legacy_source');
            $table->index(['legacy_source', 'legacy_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['legacy_source', 'legacy_employee_id']);
            $table->dropColumn(['legacy_source', 'legacy_employee_id']);
        });
    }
};
