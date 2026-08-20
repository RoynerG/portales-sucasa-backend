<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class HighlightAdminAccess
{
    public function __construct(private readonly PortalResetAccess $cargoResolver) {}

    public function canManage(User $user, ?array $resolvedCargo = null): bool
    {
        if ($user->role === 'admin' && $user->legacy_source !== 'wordpress_funcionarios') {
            return true;
        }

        $cargo = $resolvedCargo ?? $this->cargoResolver->resolveCargo($user);
        if ($cargo['id'] !== null
            && in_array($cargo['id'], config('highlight_admin.allowed_cargo_ids', [1, 6, 11, 12, 13, 14]), true)) {
            return true;
        }

        $role = Str::lower((string) ($cargo['role'] ?? ''));

        return collect(config('highlight_admin.allowed_role_keywords', ['gerencia', 'desarrollo']))
            ->contains(fn (string $keyword): bool => $keyword !== '' && Str::contains($role, $keyword));
    }
}
