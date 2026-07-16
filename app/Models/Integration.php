<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'api_class', 'description', 'icon', 'color',
        'website_url', 'config_schema', 'active', 'order',
    ];

    protected $casts = [
        'config_schema' => 'array',
        'active' => 'boolean',
    ];

    public function credentials(): HasMany
    {
        return $this->hasMany(PortalCredential::class);
    }

    public function syncStatuses(): HasMany
    {
        return $this->hasMany(PropertySyncStatus::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(PortalCategory::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }
}
