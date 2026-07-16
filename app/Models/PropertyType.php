<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyType extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'icon', 'color',
        'is_building', 'is_land', 'is_commercial',
        'active', 'order',
    ];

    protected $casts = [
        'is_building' => 'boolean',
        'is_land' => 'boolean',
        'is_commercial' => 'boolean',
        'active' => 'boolean',
    ];

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'property_type_features')
            ->withPivot('is_required', 'order')
            ->orderBy('property_type_features.order');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
