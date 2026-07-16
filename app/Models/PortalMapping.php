<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PortalMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id', 'mappable_type', 'mappable_id',
        'external_id', 'external_name', 'extra',
    ];

    protected $casts = [
        'extra' => 'array',
    ];

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }

    public function mappable(): MorphTo
    {
        return $this->morphTo();
    }
}
