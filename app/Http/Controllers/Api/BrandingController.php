<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BrandingService;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    public function show(BrandingService $branding): JsonResponse
    {
        return response()->json(['Datos' => $branding->payload()]);
    }
}
