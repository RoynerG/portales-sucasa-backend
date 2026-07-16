<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portals\GoogleSitemapGenerator;
use App\Services\Portals\ProppitFeedGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class XmlController extends Controller
{
    public function __construct(
        protected ProppitFeedGenerator $proppit,
        protected GoogleSitemapGenerator $google
    ) {}

    public function generateProppit(): JsonResponse
    {
        $path = $this->proppit->writeToFile();
        return response()->json(['Datos' => ['path' => $path, 'size' => File::size($path)]]);
    }

    public function generateGoogle(): JsonResponse
    {
        $path = $this->google->writeToFile();
        return response()->json(['Datos' => ['path' => $path, 'size' => File::size($path)]]);
    }
}
