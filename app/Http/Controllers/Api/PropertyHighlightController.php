<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WordPressHighlightAdminService;
use App\Services\WordPressHighlightService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OutOfBoundsException;

class PropertyHighlightController extends Controller
{
    public function __construct(
        private readonly WordPressHighlightService $highlights,
        private readonly WordPressHighlightAdminService $administration,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de destacados requiere la fuente de WordPress.');

        return response()->json(['Datos' => $this->highlights->index($request->query())]);
    }

    public function destroy(Request $request, string $code): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de destacados requiere la fuente de WordPress.');

        try {
            $result = $this->highlights->release(
                trim($code),
                $request->user(),
                trim((string) $request->query('mercado', '')) ?: null,
            );
        } catch (OutOfBoundsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }

    public function quotas(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de cupos requiere la fuente de WordPress.');

        return response()->json(['Datos' => $this->highlights->quotas($request->query())]);
    }

    public function myQuotas(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de cupos requiere la fuente de WordPress.');

        try {
            $result = $this->highlights->quotasForUser($request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }

    public function storeRequest(Request $request, string $code): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de solicitudes requiere la fuente de WordPress.');
        $validated = $request->validate([
            'portal' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'opportunity' => ['required', 'boolean'],
            'negotiable' => ['required', 'boolean'],
        ]);

        try {
            $result = $this->highlights->requestHighlight(
                trim($code),
                (string) $validated['portal'],
                (string) $validated['reason'],
                (bool) $validated['opportunity'],
                (bool) $validated['negotiable'],
                $request->user(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result], 201);
    }

    public function updateQuotas(Request $request, string $employee): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de cupos requiere la fuente de WordPress.');

        $values = $request->input('quotas');
        if (! is_array($values)) {
            return response()->json(['message' => 'Debes enviar los cupos por mercado.'], 422);
        }

        try {
            $result = $this->highlights->updateQuotas($employee, $values);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }

    public function updateQuotaLimits(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de cupos requiere la fuente de WordPress.');

        $values = $request->input('limits');
        if (! is_array($values)) {
            return response()->json(['message' => 'Debes enviar los límites generales por mercado.'], 422);
        }

        try {
            $result = $this->highlights->updateQuotaLimits($values);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }

    public function pending(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de solicitudes requiere la fuente de WordPress.');

        return response()->json(['Datos' => $this->highlights->pendingRequests($request->query())]);
    }

    public function complete(Request $request, string $highlightRequest): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración de solicitudes requiere la fuente de WordPress.');

        try {
            $result = $this->highlights->completeRequest($highlightRequest, $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }

    public function history(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'El historial de destacados requiere la fuente de WordPress.');

        return response()->json(['Datos' => $this->administration->history($request->query())]);
    }

    public function premium(Request $request): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración Premium requiere la fuente de WordPress.');

        return response()->json(['Datos' => $this->administration->premium($request->query())]);
    }

    public function updatePremium(Request $request, string $code): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'La administración Premium requiere la fuente de WordPress.');
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        try {
            $result = $this->administration->togglePremium(trim($code), (bool) $validated['enabled'], $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }

    public function premiumReports(string $code): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'Los reportes Premium requieren la fuente de WordPress.');

        try {
            $result = $this->administration->reports(trim($code));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result]);
    }

    public function storePremiumReport(Request $request, string $code): JsonResponse
    {
        abort_unless(config('sources.properties') === 'wordpress', 409, 'Los reportes Premium requieren la fuente de WordPress.');
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'observation' => ['required', 'string', 'min:10', 'max:3000'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $result = $this->administration->addReport(trim($code), $validated['type'], trim($validated['observation']), $validated['date'], $request->user());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['Datos' => $result], 201);
    }
}
