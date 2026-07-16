<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->where('active', true)
            ->select('id', 'email', 'name', 'avatar_path', 'bio', 'role', 'created_at')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'avatar' => $user->avatar_path,
                'detail' => $user->bio,
                'role' => $user->role,
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return response()->json(['Datos' => $users]);
    }

    public function messages(): JsonResponse
    {
        return response()->json(['Datos' => Message::active()->latest()->get()]);
    }
}
