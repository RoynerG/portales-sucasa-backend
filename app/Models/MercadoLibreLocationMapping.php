<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MercadoLibreLocationMapping extends Model
{
    protected $table = 'mercadolibre_location_mappings';

    protected $fillable = [
        'source_department', 'source_city', 'source_neighborhood',
        'state_id', 'state_name', 'city_id', 'city_name',
        'neighborhood_id', 'neighborhood_name',
    ];
}
