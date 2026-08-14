<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PortalRecoveryController extends Controller
{
    public function recover(
        Request $request,
        string $portal,
        CiencuadrasController $ciencuadras,
        FincaraizController $fincaraiz,
        MercadoLibreController $mercadolibre,
        ProppitController $proppit,
    ): JsonResponse {
        $data = $request->validate([
            'codes' => ['required', 'array', 'min:1', 'max:20'],
            'codes.*' => ['required', 'string', 'distinct', 'max:32'],
            'mode' => ['sometimes', 'in:error,missing'],
        ]);
        $mode = $data['mode'] ?? 'error';
        $integration = Integration::query()->active()->where('slug', $portal)->firstOrFail();
        $codes = collect($data['codes'])->map(fn ($code) => trim((string) $code))->filter()->unique()->values();
        $properties = Property::query()->whereIn('code', $codes)->get()->keyBy('code');
        $statuses = PropertySyncStatus::query()
            ->where('integration_id', $integration->id)
            ->whereIn('property_id', $properties->pluck('id'))
            ->latest('updated_at')
            ->get()
            ->groupBy('property_id');
        $results = [];

        foreach ($codes as $code) {
            $property = $properties->get($code);
            if (! $property) {
                $results[] = $this->result($code, false, 'skip', 'El inmueble no existe en la base local.');

                continue;
            }

            $sync = $statuses->get($property->id)?->first();
            $actionRequest = clone $request;

            try {
                [$action, $response] = match ($portal) {
                    'ciencuadras' => $this->recoverCiencuadras($actionRequest, $code, $ciencuadras),
                    'fincaraiz' => $this->recoverFincaraiz($actionRequest, $code, $sync, $mode, $fincaraiz),
                    'mercadolibre' => $this->recoverMercadoLibre($actionRequest, $code, $sync, $mode, $mercadolibre),
                    'proppit' => $this->recoverProppit($actionRequest, $code, $sync, $proppit),
                    default => throw new \RuntimeException('Este portal no admite recuperación automática.'),
                };
                $results[] = $this->responseResult($code, $action, $response);
            } catch (Throwable $exception) {
                report($exception);
                $results[] = $this->result($code, false, 'error', $exception->getMessage());
            }
        }

        $accepted = collect($results)->where('ok', true)->count();

        return response()->json(['Datos' => [
            'portal' => $portal,
            'mode' => $mode,
            'requested' => $codes->count(),
            'accepted' => $accepted,
            'rejected' => count($results) - $accepted,
            'results' => $results,
        ]]);
    }

    protected function recoverCiencuadras(Request $request, string $code, CiencuadrasController $controller): array
    {
        try {
            return ['publish', $controller->publish($request, $code)];
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
        }

        return ['update', $controller->update($request, $code)];
    }

    protected function recoverFincaraiz(
        Request $request,
        string $code,
        ?PropertySyncStatus $sync,
        string $mode,
        FincaraizController $controller
    ): array {
        $response = $controller->recover($request, $code);
        $action = (string) data_get($response->getData(true), 'Datos.recovery_action', 'recover');

        return [$action, $response];
    }

    protected function recoverMercadoLibre(
        Request $request,
        string $code,
        ?PropertySyncStatus $sync,
        string $mode,
        MercadoLibreController $controller
    ): array {
        $operation = in_array($sync?->portal_variant, ['sale', 'rent'], true) ? $sync->portal_variant : 'all';
        $request->merge(['operation' => $operation]);

        if ($sync?->external_id) {
            return $mode === 'missing'
                ? ['activate', $controller->activate($request, $code)]
                : ['update', $controller->update($request, $code)];
        }

        return ['publish', $controller->publish($request, $code)];
    }

    protected function recoverProppit(
        Request $request,
        string $code,
        ?PropertySyncStatus $sync,
        ProppitController $controller
    ): array {
        if ($sync?->external_id) {
            $verification = $controller->verify($request, $code);
            $verificationData = $verification->getData(true)['Datos'] ?? [];
            if ($verification->getStatusCode() < 400 && ($verificationData['ok'] ?? false)) {
                return ['update', $controller->update($request, $code)];
            }
        }

        return ['publish', $controller->publish($request, $code)];
    }

    protected function responseResult(string $code, string $action, JsonResponse $response): array
    {
        $data = $response->getData(true)['Datos'] ?? [];
        $ok = $response->getStatusCode() < 400 && ($data['ok'] ?? true) !== false;
        $message = $ok
            ? 'Acción enviada al portal.'
            : $this->responseMessage($data, $response->getStatusCode());

        return $this->result($code, $ok, $action, $message, $response->getStatusCode(), $data);
    }

    protected function responseMessage(array $data, int $status): string
    {
        $errors = $data['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            return implode(' ', array_map('strval', $errors));
        }

        return (string) ($data['message'] ?? data_get($data, 'error.message') ?? data_get($data, 'data.error') ?? "El portal rechazó la acción ({$status}).");
    }

    protected function result(
        string $code,
        bool $ok,
        string $action,
        string $message,
        ?int $statusCode = null,
        array $response = []
    ): array {
        return compact('code', 'ok', 'action', 'message', 'statusCode', 'response');
    }
}
