<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasActiveProperties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalBulkController extends Controller
{
    private const PORTALS = ['mercadolibre', 'fincaraiz', 'ciencuadras', 'proppit'];

    public function __construct(
        protected CiencuadrasActiveProperties $ciencuadrasProperties,
    ) {}

    public function candidates(Request $request, string $portal): JsonResponse
    {
        abort_unless(in_array($portal, self::PORTALS, true), 404, 'Portal no soportado.');
        $data = $request->validate([
            'action' => ['required', 'in:update,pause'],
            'fresh' => ['nullable', 'boolean'],
        ]);

        if ($portal === 'ciencuadras') {
            return $this->ciencuadrasCandidates($request, $data['action']);
        }

        $integration = Integration::query()
            ->where('slug', $portal)
            ->where('active', true)
            ->firstOrFail();
        $environment = $this->environment($portal);
        $statuses = PropertySyncStatus::query()
            ->with('property:id,code')
            ->where('integration_id', $integration->id)
            ->where('environment', $environment)
            ->where('sync_status', 'synced')
            ->whereNotNull('external_id')
            ->get()
            ->filter(fn (PropertySyncStatus $status) => filled($status->property?->code));
        $items = $statuses
            ->groupBy(fn (PropertySyncStatus $status) => (string) $status->property->code)
            ->map(fn ($rows, string $code) => [
                'code' => $code,
                'operations' => $portal === 'mercadolibre'
                    ? $rows->pluck('portal_variant')->filter()->unique()->values()->all()
                    : [],
            ])
            ->values();

        return response()->json(['Datos' => [
            'portal' => $portal,
            'action' => $data['action'],
            'environment' => $environment,
            'total' => $items->count(),
            'codes' => $items->pluck('code')->values(),
            'items' => $items,
        ]]);
    }

    protected function ciencuadrasCandidates(Request $request, string $action): JsonResponse
    {
        $codes = $this->ciencuadrasProperties->sourceCodes(
            $request->boolean('fresh', true)
        );
        abort_if($codes === null, 503, 'No fue posible consultar el inventario de Ciencuadras.');

        $existingCodes = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->whereIn('codigo', $codes)
            ->pluck('codigo')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        return response()->json(['Datos' => [
            'portal' => 'ciencuadras',
            'action' => $action,
            'environment' => $this->environment('ciencuadras'),
            'total' => $existingCodes->count(),
            'codes' => $existingCodes,
            'items' => $existingCodes->map(fn (string $code) => [
                'code' => $code,
                'operations' => [],
            ]),
        ]]);
    }

    protected function environment(string $portal): string
    {
        return match ($portal) {
            'ciencuadras' => (string) config('portals.ciencuadras.environment', 'production'),
            'mercadolibre' => (string) config('portals.mercadolibre.environment', 'production'),
            default => 'production',
        };
    }
}
