<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $integrationId = DB::table('integrations')->where('slug', 'ciencuadras')->value('id');

        if (! $integrationId) {
            return;
        }

        DB::table('property_sync_statuses')
            ->where('integration_id', $integrationId)
            ->whereNotNull('external_id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $normalized = preg_replace('/^(\d+-)P(?=\d)/i', '$1', (string) $row->external_id);

                    if ($normalized && $normalized !== $row->external_id) {
                        DB::table('property_sync_statuses')
                            ->where('id', $row->id)
                            ->update([
                                'external_id' => $normalized,
                                'updated_at' => now(),
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        //
    }
};
