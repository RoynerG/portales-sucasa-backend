<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'email', 'password', 'name', 'phone', 'avatar_path', 'bio',
        'role', 'active', 'legacy_source', 'legacy_employee_id', 'preferences',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'created_by');
    }

    public function consultant(): HasMany
    {
        return $this->hasMany(Consultant::class);
    }

    public function portalCredentials(): HasMany
    {
        return $this->hasMany(PortalCredential::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'manager'], true);
    }
}
