<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_credentials', function (Blueprint $table) {
            $table->string('account_key', 100)->nullable()->after('integration_id');
        });

        DB::table('portal_credentials')
            ->orderBy('id')
            ->get()
            ->each(function ($credential): void {
                $updates = ['account_key' => 'user:'.$credential->user_id];

                foreach (['access_token', 'refresh_token'] as $column) {
                    $value = $credential->{$column};
                    if (! $value) {
                        continue;
                    }

                    try {
                        Crypt::decryptString($value);
                    } catch (Throwable) {
                        $updates[$column] = Crypt::encryptString($value);
                    }
                }

                DB::table('portal_credentials')->where('id', $credential->id)->update($updates);
            });

        Schema::table('portal_credentials', function (Blueprint $table) {
            $table->unique(['integration_id', 'account_key'], 'portal_credentials_account_unique');
        });
    }

    public function down(): void
    {
        Schema::table('portal_credentials', function (Blueprint $table) {
            $table->dropUnique('portal_credentials_account_unique');
            $table->dropColumn('account_key');
        });
    }
};
