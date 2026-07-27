<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portals\GoogleSitemapGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class XmlController extends Controller
{
    public function __construct(
        protected GoogleSitemapGenerator $google
    ) {}

    public function generateGoogle(): JsonResponse
    {
        $path = $this->google->writeToFile();
        return response()->json(['Datos' => ['path' => $path, 'size' => File::size($path)]]);
    }
}
