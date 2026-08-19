<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'title', 'description', 'condition',
        'city_id', 'neighborhood_id', 'address', 'address_extra',
        'lat', 'lng', 'show_exact_address',
        'property_type_id', 'transaction_type_id',
        'sale_price', 'rent_price', 'admin_price', 'currency', 'price_negotiable',
        'area_total', 'area_built', 'area_private', 'area_land',
        'bedrooms', 'bathrooms', 'half_bathrooms',
        'parking_spaces', 'parking_type',
        'floor_number', 'age_years', 'year_built',
        'stratum', 'furnished',
        'project_name', 'in_closed_complex',
        'status', 'featured', 'exclusive', 'published_at', 'expires_at',
        'consultant_id', 'created_by', 'updated_by',
        'contact_name', 'contact_phone', 'contact_whatsapp', 'contact_email',
        'views_count', 'leads_count',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'rent_price' => 'decimal:2',
            'admin_price' => 'decimal:2',
            'area_total' => 'decimal:2',
            'area_built' => 'decimal:2',
            'area_private' => 'decimal:2',
            'area_land' => 'decimal:2',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'price_negotiable' => 'boolean',
            'furnished' => 'boolean',
            'in_closed_complex' => 'boolean',
            'show_exact_address' => 'boolean',
            'featured' => 'boolean',
            'exclusive' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // Relaciones
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function propertyType(): BelongsTo
    {
        return $this->belongsTo(PropertyType::class);
    }

    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)->orderBy('order');
    }

    public function coverImage()
    {
        return $this->hasOne(PropertyImage::class)->where('is_cover', true);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(PropertyVideo::class)->orderBy('order');
    }

    public function floorPlans(): HasMany
    {
        return $this->hasMany(PropertyFloorPlan::class)->orderBy('order');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'property_feature')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function syncStatuses(): HasMany
    {
        return $this->hasMany(PropertySyncStatus::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PropertyStatusHistory::class);
    }

    // Scopes
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'active')
            ->whereNotNull('published_at')
            ->where(fn ($q2) => $q2->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeFeatured(Builder $q): Builder
    {
        return $q->where('featured', true);
    }

    public function scopeOfCity(Builder $q, int $cityId): Builder
    {
        return $q->where('city_id', $cityId);
    }

    public function scopeInPriceRange(Builder $q, ?float $min, ?float $max, string $field = 'sale_price'): Builder
    {
        if ($min !== null) $q->where($field, '>=', $min);
        if ($max !== null) $q->where($field, '<=', $max);
        return $q;
    }

    public function scopeWithPortalState(Builder $query, ?string $portal, ?string $state): Builder
    {
        $portal = trim((string) $portal);
        $state = trim((string) $state);

        if ($portal !== '' && ! in_array($portal, PropertySyncStatus::PORTALS, true)) {
            return $query->whereRaw('1 = 0');
        }

        if ($portal === '' && $state === '') {
            return $query;
        }

        $statusConstraint = function (Builder $statuses, ?array $states = null) use ($portal): void {
            $statuses->forCurrentPortalEnvironment($portal ?: null);
            if ($states !== null) {
                $statuses->whereIn('sync_status', $states);
            }
        };

        if ($state === 'not_published') {
            return $query->whereDoesntHave(
                'syncStatuses',
                fn (Builder $statuses) => $statusConstraint($statuses, ['synced'])
            );
        }

        $states = match ($state) {
            'published' => ['synced'],
            'updating' => ['pending', 'syncing'],
            'error' => ['error'],
            'paused' => ['paused', 'closed'],
            default => null,
        };

        if ($state !== '' && $states === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'syncStatuses',
            fn (Builder $statuses) => $statusConstraint($statuses, $states)
        );
    }

    // Accesores
    public function getDisplayPriceAttribute(): ?float
    {
        $tx = $this->transactionType;
        if ($tx?->slug === 'rent' || $tx?->slug === 'sale_rent') {
            return $this->rent_price ? (float) $this->rent_price : (float) $this->sale_price;
        }
        return $this->sale_price ? (float) $this->sale_price : null;
    }

    public function getDisplayAreaAttribute(): ?float
    {
        return $this->area_built ?? $this->area_total ?? $this->area_land;
    }
}
