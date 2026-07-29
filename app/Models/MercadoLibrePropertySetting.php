<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MercadoLibrePropertySetting extends Model
{
    protected $table = 'mercadolibre_property_settings';

    protected $fillable = [
        'property_id', 'operation', 'listing_type_id', 'category_id',
        'attributes', 'location', 'show_exact_address',
    ];

    protected $casts = [
        'attributes' => 'array',
        'location' => 'array',
        'show_exact_address' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
