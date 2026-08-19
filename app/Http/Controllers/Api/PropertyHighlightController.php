<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WordPressHighlightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OutOfBoundsException;

class PropertyHighlightController extends Controller
{
    public function __construct(private readonly WordPressHighlightService $highlights) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de destacados requiere la fuente de WordPress.');

        return response()->json(['Datos' => $this->highlights->index($request->query())]);
    }

    public function destroy(Request $request, string $code): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de destacados requiere la fuente de WordPress.');

        try {
            $result = $this->highlights->release(trim($code), $request->user());
        } catch (OutOfBoundsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }
}
