<?php

namespace App\Services\Portals;

use App\Models\PortalCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CiencuadrasActiveProperties
{
    public function __construct(protected CiencuadrasClient $client) {}

    public function codes(bool $fresh = false): ?Collection
    {
        $cacheKey = 'ciencuadras.active-properties.' . config('portals.ciencuadras.environment');

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinute(), function () {
            $login = $this->client->login();
            $token = ($login['ok'] ?? false)
                ? $this->client->extractToken($login['data'] ?? [])
                : null;

            if (! $token) {
                return null;
            }

            $result = $this->client->consultAllProperties(
                new PortalCredential(['access_token' => $token])
            );

            if (! ($result['ok'] ?? false)) {
                return null;
            }

            return collect($result['data']['message'] ?? [])
                ->pluck('propertyCode')
                ->map(fn ($code) => trim((string) $code))
                ->filter()
                ->unique()
                ->values();
        });
    }

    public function sourceCode(string $portalCode): string
    {
        $prefix = preg_quote((string) config('portals.ciencuadras.property_code_prefix', '22130-'), '/');

        return preg_replace('/^' . $prefix . 'P?/i', '', trim($portalCode)) ?? trim($portalCode);
    }

    public function sourceCodes(bool $fresh = false): ?Collection
    {
        return $this->codes($fresh)
            ?->map(fn (string $code) => $this->sourceCode($code))
            ->filter()
            ->unique()
            ->values();
    }
}
