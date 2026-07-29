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
        return $this->inventory($fresh)
            ?->pluck('propertyCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
    }

    public function activeCodes(bool $fresh = false): ?Collection
    {
        return $this->inventory($fresh)
            ?->filter(fn (array $property) => $this->isActiveInventoryProperty($property))
            ->pluck('propertyCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
    }

    public function activeSourceCodes(bool $fresh = false): ?Collection
    {
        return $this->activeCodes($fresh)
            ?->reject(fn (string $code) => $this->isLegacyCode($code))
            ->map(fn (string $code) => $this->sourceCode($code))
            ->filter()
            ->unique()
            ->values();
    }

    public function inspectSourceCodes(array|Collection $sourceCodes, PortalCredential $credential): Collection
    {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $portalCodes = collect($sourceCodes)
            ->map(fn ($code) => $this->sourceCode((string) $code))
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $code) => [$code => $prefix.$code]);
        $results = $this->client->consultProperties($portalCodes->values()->all(), $credential);

        return $portalCodes->map(function (string $portalCode) use ($results) {
            $result = $results[$portalCode] ?? null;
            if (! ($result['ok'] ?? false)) {
                return [
                    'state' => 'unavailable',
                    'property' => null,
                    'response' => $result['data'] ?? null,
                ];
            }

            return $this->stateFromResponse($result['data'] ?? []);
        });
    }

    protected function inventory(bool $fresh = false): ?Collection
    {
        $cacheKey = 'ciencuadras.properties-inventory.'.config('portals.ciencuadras.environment');

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
                ->filter(fn ($property) => is_array($property) && ! empty($property['propertyCode']))
                ->reject(fn (array $property) => $this->isLegacyCode((string) $property['propertyCode']))
                ->values();
        });
    }

    protected function isActiveInventoryProperty(array $property): bool
    {
        $active = strtolower(trim((string) ($property['active'] ?? '')));
        $status = trim((string) ($property['status'] ?? ''));

        if (in_array($status, ['5', '8'], true)
            || str_contains($active, 'inactivo')
            || str_contains($active, 'eliminado')) {
            return false;
        }

        return $active === 'activo' || in_array($status, ['0', '4'], true);
    }

    protected function stateFromResponse(array $response): array
    {
        $message = $response['message'] ?? null;
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
                'response' => $response,
            ];
        }

        return [
            'state' => $this->isActiveInventoryProperty($property) ? 'active' : 'inactive',
            'property' => $property,
            'response' => $response,
        ];
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

    public function sourceCodes(bool $fresh = false): ?Collection
    {
        return $this->cleanCodes($fresh)
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
