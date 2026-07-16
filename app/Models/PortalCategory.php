<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id', 'external_id', 'name',
        'parent_external_id', 'level', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
