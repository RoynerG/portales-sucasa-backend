<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\PortalCatalogAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalCatalogAuditController extends Controller
{
    public function __construct(protected PortalCatalogAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['Datos' => $this->audit->overview($request->user()?->id)]);
    }

    public function verify(Request $request, string $portal): JsonResponse
    {
        return response()->json(['Datos' => $this->audit->audit($portal, $request->user()?->id)]);
    }

    public function importFincaraizExport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['nullable', 'string', 'max:255'],
            'listings' => ['required', 'array', 'min:1', 'max:1500'],
            'listings.*.code' => ['required', 'string', 'max:120'],
            'listings.*.fr_property_id' => ['required', 'string', 'max:120'],
            'listings.*.status' => ['required', 'string', 'max:40'],
        ]);

        return response()->json(['Datos' => $this->audit->importFincaraizExport(
            $request->user()?->id,
            $data['listings'],
            $data['filename'] ?? null,
        )]);
    }
}
