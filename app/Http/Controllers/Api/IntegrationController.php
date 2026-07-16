<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Integration::query()
            ->active()
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json(['Datos' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'unique:integrations,name'],
            'slug' => ['required', 'string', 'unique:integrations,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string'],
            'color' => ['nullable', 'string'],
            'website_url' => ['nullable', 'string'],
            'config_schema' => ['nullable', 'array'],
            'active' => ['boolean'],
            'order' => ['nullable', 'integer'],
        ]);

        $integration = Integration::create($data);
        return response()->json(['Datos' => [$integration]], 201);
    }

    public function update(Request $request, Integration $integration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'unique:integrations,name,' . $integration->id],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string'],
            'color' => ['nullable', 'string'],
            'website_url' => ['nullable', 'string'],
            'config_schema' => ['nullable', 'array'],
            'active' => ['boolean'],
            'order' => ['nullable', 'integer'],
        ]);
        $integration->update($data);
        return response()->json(['Datos' => [$integration]]);
    }

    public function destroy(Integration $integration): JsonResponse
    {
        $integration->update(['active' => false]);
        return response()->json(['Datos' => 'OK']);
    }
}
