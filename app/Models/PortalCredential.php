<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'integration_id', 'account_key', 'access_token', 'refresh_token',
        'access_token_expires_at', 'data',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'data' => 'array',
        'access_token_expires_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isExpired(): bool
    {
        return $this->access_token_expires_at?->isPast() ?? true;
    }

    public function expiresSoon(int $seconds = 60): bool
    {
        return ! $this->access_token_expires_at
            || $this->access_token_expires_at->lte(now()->addSeconds($seconds));
    }
}
