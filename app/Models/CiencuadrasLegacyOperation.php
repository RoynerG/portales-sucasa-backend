<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CiencuadrasLegacyOperation extends Model
{
    protected $fillable = [
        'legacy_code',
        'source_code',
        'status',
        'id_request',
        'last_response',
        'last_error',
        'requested_by',
        'requested_at',
        'verified_at',
    ];

    protected $casts = [
        'last_response' => 'array',
        'requested_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
