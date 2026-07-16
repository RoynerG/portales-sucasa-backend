<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WordPressFuncionarioAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, WordPressFuncionarioAuthenticator $funcionarioAuth): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) ($request->input('usuario') ?? $request->input('email') ?? $request->input('login')));
        $password = (string) $request->input('password');

        if ($login === '') {
            throw ValidationException::withMessages([
                'email' => ['El usuario es obligatorio.'],
            ]);
        }

        try {
            $user = $funcionarioAuth->enabled()
                ? $funcionarioAuth->attempt($login, $password)
                : User::where('email', $login)->first();
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'email' => ['No se pudo conectar con la base de funcionarios. Revisa WORDPRESS_DB_* en .env.'],
            ]);
        }

        if (! $user || (! $funcionarioAuth->enabled() && ! Hash::check($password, $user->password))) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        if (! $user->active) {
            throw ValidationException::withMessages([
                'email' => ['Usuario inactivo. Contacta al administrador.'],
            ]);
        }

        $device = $request->userAgent() ?: 'unknown';
        $token = $user->createToken($device)->plainTextToken;
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return response()->json([
            'Datos' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'avatar' => $user->avatar_path,
                    'detail' => $user->bio,
                    'role' => $user->role,
                    'legacy_employee_id' => $user->legacy_employee_id,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['Datos' => 'OK']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'Datos' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'avatar' => $user->avatar_path,
                'detail' => $user->bio,
                'role' => $user->role,
                'legacy_employee_id' => $user->legacy_employee_id,
            ],
        ]);
    }
}
