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
        $cacheKey = 'ciencuadras.active-properties.'.config('portals.ciencuadras.environment');

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

        return preg_replace('/^'.$prefix.'P?/i', '', trim($portalCode)) ?? trim($portalCode);
    }

    public function cleanCodes(bool $fresh = false): ?Collection
    {
        return $this->codes($fresh)
            ?->reject(fn (string $code) => $this->isLegacyCode($code))
            ->values();
    }

    public function legacyCodes(bool $fresh = false): ?Collection
    {
        return $this->codes($fresh)
            ?->filter(fn (string $code) => $this->isLegacyCode($code))
            ->values();
    }

    public function sourceCodes(bool $fresh = false): ?Collection
    {
        return $this->cleanCodes($fresh)
            ?->map(fn (string $code) => $this->sourceCode($code))
            ->filter()
            ->unique()
            ->values();
    }

    public function legacySourceCodes(bool $fresh = false): ?Collection
    {
        return $this->legacyCodes($fresh)
            ?->map(fn (string $code) => $this->sourceCode($code))
            ->filter()
            ->unique()
            ->values();
    }

    public function legacyCodeForSource(string $sourceCode): string
    {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');

        return $prefix.'P'.$this->sourceCode($sourceCode);
    }

    public function inspectLegacyCode(
        string $legacyCode,
        ?PortalCredential $credential = null,
        bool $fresh = false
    ): ?array {
        $cacheKey = 'ciencuadras.legacy-property.'
            .config('portals.ciencuadras.environment')
            .'.'.sha1($legacyCode);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($legacyCode, $credential) {
            $credential ??= $this->credential();
            if (! $credential) {
                return null;
            }

            $result = $this->client->consultProperty($legacyCode, $credential);
            if (! ($result['ok'] ?? false)) {
                return null;
            }

            $message = $result['data']['message'] ?? null;
            $property = is_array($message)
                && array_is_list($message)
                && isset($message[0])
                && is_array($message[0])
                    ? $message[0]
                    : null;

            if (! $property) {
                return [
                    'state' => 'missing',
                    'property' => null,
                    'response' => $result['data'] ?? null,
                ];
            }

            $activeLabel = strtolower(trim((string) ($property['active'] ?? '')));
            $status = trim((string) ($property['status'] ?? ''));
            $inactive = in_array($status, ['5', '8'], true)
                || str_contains($activeLabel, 'inactivo')
                || str_contains($activeLabel, 'eliminado');

            return [
                'state' => $inactive ? 'inactive' : ($activeLabel === 'activo' ? 'active' : 'unknown'),
                'property' => $property,
                'response' => $result['data'] ?? null,
            ];
        });
    }

    public function isLegacyCode(string $portalCode): bool
    {
        $prefix = preg_quote((string) config('portals.ciencuadras.property_code_prefix', '22130-'), '/');

        return preg_match('/^'.$prefix.'P/i', trim($portalCode)) === 1;
    }

    protected function credential(): ?PortalCredential
    {
        $login = $this->client->login();
        $token = ($login['ok'] ?? false)
            ? $this->client->extractToken($login['data'] ?? [])
            : null;

        return $token ? new PortalCredential(['access_token' => $token]) : null;
    }
}
