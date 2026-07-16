<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionType extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'has_sale_price', 'has_rent_price', 'has_admin_price',
        'active', 'order',
    ];

    protected $casts = [
        'has_sale_price' => 'boolean',
        'has_rent_price' => 'boolean',
        'has_admin_price' => 'boolean',
        'active' => 'boolean',
    ];

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
