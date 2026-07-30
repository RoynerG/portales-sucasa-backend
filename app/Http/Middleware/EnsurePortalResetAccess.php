<?php

namespace App\Http\Middleware;

use App\Services\PortalResetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalResetAccess
{
    public function __construct(private readonly PortalResetAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->access->canReset($user)) {
            return response()->json([
                'message' => 'Esta configuración está disponible únicamente para Gerencias y Desarrollo.',
            ], 403);
        }

        return $next($request);
    }
}
