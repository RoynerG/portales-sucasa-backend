<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalResetEvent extends Model
{
    protected $fillable = [
        'user_id',
        'legacy_employee_id',
        'user_name',
        'deleted_counts',
        'backup_file',
        'backup_checksum',
        'ip_address',
    ];

    protected $casts = [
        'deleted_counts' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
