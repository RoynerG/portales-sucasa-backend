<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WordPressFuncionarioAuthenticator
{
    public function enabled(): bool
    {
        return config('sources.auth') === 'wordpress_funcionarios';
    }

    public function attempt(string $login, string $password): ?User
    {
        if (! $this->enabled()) {
            return null;
        }

        $login = trim($login);
        $funcionario = DB::connection('wordpress')
            ->table('wp_jet_cct_funcionarios')
            ->where(function ($query) use ($login) {
                $query->where('user_others_apss', $login)
                    ->orWhere('correo', $login);
            })
            ->where(function ($query) {
                $query->whereNull('cct_status')
                    ->orWhere('cct_status', '')
                    ->orWhere('cct_status', 'publish');
            })
            ->first();

        if (! $funcionario || ! $this->isActive($funcionario) || ! $this->passwordMatches($password, (string) ($funcionario->pass_others_apss ?? ''))) {
            return null;
        }

        return $this->syncUser($funcionario, $login);
    }

    private function isActive(object $funcionario): bool
    {
        return in_array(strtolower(trim((string) ($funcionario->activo ?? ''))), ['si', 'sí', '1', 'true', 'activo'], true);
    }

    private function passwordMatches(string $plain, string $stored): bool
    {
        $stored = trim($stored);

        if ($stored === '') {
            return false;
        }

        if (Str::startsWith($stored, ['$2y$', '$2a$', '$argon2i$', '$argon2id$'])) {
            return Hash::check($plain, $stored);
        }

        return hash_equals($stored, $plain);
    }

    private function syncUser(object $funcionario, string $login): User
    {
        $employeeId = trim((string) ($funcionario->id_empleado ?? ''));
        if ($employeeId === '') {
            $employeeId = (string) ($funcionario->_ID ?? $login);
        }

        $email = filter_var($funcionario->correo ?? null, FILTER_VALIDATE_EMAIL)
            ? trim((string) $funcionario->correo)
            : Str::slug($login ?: $employeeId).'@sucasa.local';

        $role = $this->mapRole((string) ($funcionario->rol ?? ''));
        $name = trim((string) ($funcionario->nombre ?? '')) ?: $login;

        $user = User::query()
            ->where('legacy_source', 'wordpress_funcionarios')
            ->where('legacy_employee_id', $employeeId)
            ->first();

        if (! $user) {
            $user = User::where('email', $email)->first() ?: new User;
        }

        $user->fill([
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            'name' => $name,
            'phone' => $funcionario->celular ?? null,
            'bio' => $funcionario->gestion ?? null,
            'role' => $role,
            'active' => true,
            'legacy_source' => 'wordpress_funcionarios',
            'legacy_employee_id' => $employeeId,
            'preferences' => [
                'legacy_row_id' => $funcionario->_ID ?? null,
                'legacy_employee_id' => $employeeId,
                'legacy_user' => $funcionario->user_others_apss ?? $login,
                'rol' => $funcionario->rol ?? null,
                'id_cargo' => isset($funcionario->id_cargo) && trim((string) $funcionario->id_cargo) !== ''
                    ? (int) $funcionario->id_cargo
                    : null,
                'gestion' => $funcionario->gestion ?? null,
                'id_sucursal' => $funcionario->id_sucursal ?? null,
            ],
        ]);
        $user->save();

        return $user;
    }

    private function mapRole(string $role): string
    {
        $role = Str::lower($role);

        if (Str::contains($role, ['admin', 'geren', 'director', 'super'])) {
            return 'manager';
        }

        if (Str::contains($role, ['asesor', 'comercial', 'agente'])) {
            return 'agent';
        }

        return 'viewer';
    }
}
