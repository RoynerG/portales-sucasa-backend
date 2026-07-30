<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\PortalResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalResetController extends Controller
{
    public function __construct(private readonly PortalResetService $resetService) {}

    public function preview(): JsonResponse
    {
        return response()->json(['Datos' => $this->resetService->preview()]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => [
                'required',
                'string',
                Rule::in([(string) config('portal_reset.confirmation_phrase')]),
            ],
        ], [
            'confirmation.required' => 'Escribe la frase de confirmación.',
            'confirmation.in' => 'La frase de confirmación no coincide.',
        ]);

        return response()->json([
            'Datos' => $this->resetService->reset($request->user(), $request->ip()),
        ]);
    }
}
