<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PortalResetAccess
{
    public function canReset(User $user, ?array $resolvedCargo = null): bool
    {
        if ($user->role === 'admin' && $user->legacy_source !== 'wordpress_funcionarios') {
            return true;
        }

        $cargo = $resolvedCargo ?? $this->resolveCargo($user);

        if ($cargo['id'] !== null
            && in_array($cargo['id'], config('portal_reset.allowed_cargo_ids', [11, 12, 13, 14]), true)) {
            return true;
        }

        $role = Str::lower((string) ($cargo['role'] ?? ''));

        return collect(config('portal_reset.allowed_role_keywords', ['gerencia', 'desarrollo']))
            ->contains(fn (string $keyword): bool => $keyword !== '' && Str::contains($role, $keyword));
    }

    public function resolveCargo(User $user): array
    {
        $preferences = $user->preferences ?: [];
        $cargoId = $this->integerOrNull($preferences['id_cargo'] ?? null);
        $role = trim((string) ($preferences['rol'] ?? ''));

        if ($user->legacy_source === 'wordpress_funcionarios' && $user->legacy_employee_id) {
            try {
                $funcionario = DB::connection('wordpress')
                    ->table('wp_jet_cct_funcionarios')
                    ->where('id_empleado', $user->legacy_employee_id)
                    ->first(['id_cargo', 'rol']);

                if (! $funcionario) {
                    return ['id' => null, 'role' => null, 'label' => null];
                }

                $cargoId = $this->integerOrNull($funcionario->id_cargo);
                $role = trim((string) ($funcionario->rol ?? $role));
            } catch (\Throwable) {
                return ['id' => null, 'role' => null, 'label' => null];
            }
        }

        return [
            'id' => $cargoId,
            'role' => $role ?: null,
            'label' => $this->cargoLabel($cargoId, $role),
        ];
    }

    private function cargoLabel(?int $cargoId, string $role): ?string
    {
        if ($role !== '') {
            return $role;
        }

        return match ($cargoId) {
            11 => 'Gerencia Administrativa',
            12 => 'Gerencia Comercial',
            13 => 'Desarrollo',
            14 => 'Gerencia General',
            default => null,
        };
    }

    private function integerOrNull(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }
}
