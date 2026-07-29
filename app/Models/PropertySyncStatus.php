<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySyncStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'integration_id', 'environment', 'portal_variant', 'sync_status',
        'external_id', 'external_url', 'last_response', 'last_error',
        'last_synced_at', 'last_attempt_at', 'attempts',
    ];

    protected $casts = [
        'last_response' => 'array',
        'last_synced_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
