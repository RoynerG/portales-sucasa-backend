<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySyncStatus extends Model
{
    use HasFactory;

    public const PORTALS = ['mercadolibre', 'fincaraiz', 'ciencuadras', 'proppit'];

    protected $fillable = [
        'property_id', 'integration_id', 'environment', 'portal_variant', 'sync_status',
        'external_id', 'external_url', 'last_response', 'last_error',
        'last_synced_at', 'last_attempt_at', 'attempts',
    ];

    protected $casts = [
        'last_response' => 'array',
        'last_synced_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function scopeForCurrentPortalEnvironment(Builder $query, ?string $portal = null): Builder
    {
        $portals = $portal ? [$portal] : self::PORTALS;

        return $query->where(function (Builder $environments) use ($portals): void {
            foreach ($portals as $slug) {
                $environment = self::environmentFor($slug);
                $environments->orWhere(function (Builder $status) use ($slug, $environment): void {
                    $status
                        ->whereHas('integration', fn (Builder $integration) => $integration->where('slug', $slug))
                        ->where(function (Builder $value) use ($environment): void {
                            $value->where('environment', $environment)->orWhereNull('environment');
                        });
                });
            }
        });
    }

    public static function environmentFor(string $portal): string
    {
        return match ($portal) {
            'ciencuadras' => (string) config('portals.ciencuadras.environment', 'production'),
            'mercadolibre' => (string) config('portals.mercadolibre.environment', 'production'),
            'fincaraiz' => (string) config('portals.fincaraiz.environment', 'qa'),
            default => 'production',
        };
    }
}
