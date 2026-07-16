<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    use HasFactory;

    protected $fillable = ['group', 'name', 'slug', 'icon', 'description', 'active', 'order'];

    public function propertyTypes(): BelongsToMany
    {
        return $this->belongsToMany(PropertyType::class, 'property_type_features')
            ->withPivot('is_required', 'order');
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_feature')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function scopeOfGroup($query, string $group)
    {
        return $query->where('group', $group);
    }
}
