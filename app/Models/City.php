<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory;

    protected $fillable = ['dane_code', 'name', 'department', 'country_code', 'lat', 'lng', 'active'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'active' => 'boolean',
    ];

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(Neighborhood::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
