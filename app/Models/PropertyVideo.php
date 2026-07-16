<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'url', 'provider', 'thumbnail_url',
        'title', 'duration_seconds', 'order',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
