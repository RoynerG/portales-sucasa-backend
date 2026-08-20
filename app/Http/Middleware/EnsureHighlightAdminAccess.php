<?php

namespace App\Http\Middleware;

use App\Services\HighlightAdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHighlightAdminAccess
{
    public function __construct(private readonly HighlightAdminAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->access->canManage($user)) {
            return response()->json([
                'message' => 'No tienes permiso para administrar destacados, cupos o Premium.',
            ], 403);
        }

        return $next($request);
    }
}
