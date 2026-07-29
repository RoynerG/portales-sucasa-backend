<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MercadoLibreNotification extends Model
{
    protected $table = 'mercadolibre_notifications';

    protected $fillable = [
        'notification_id', 'topic', 'resource', 'external_user_id',
        'application_id', 'payload', 'status', 'error', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
