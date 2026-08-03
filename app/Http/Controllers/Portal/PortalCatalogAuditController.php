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
}
