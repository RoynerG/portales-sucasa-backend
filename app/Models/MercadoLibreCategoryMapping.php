<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MercadoLibreCategoryMapping extends Model
{
    protected $table = 'mercadolibre_category_mappings';

    protected $fillable = [
        'property_type_slug', 'operation', 'category_id', 'category_path',
        'settings', 'attributes', 'is_leaf', 'synced_at',
    ];

    protected $casts = [
        'category_path' => 'array',
        'settings' => 'array',
        'attributes' => 'array',
        'is_leaf' => 'boolean',
        'synced_at' => 'datetime',
    ];
}
