<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'url', 'thumbnail_url', 'alt_text',
        'width', 'height', 'file_size', 'is_cover', 'order',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'order' => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
