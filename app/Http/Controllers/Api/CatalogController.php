<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Feature;
use App\Models\Neighborhood;
use App\Models\PropertyType;
use App\Models\TransactionType;
use App\Services\WordPressPropertyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(protected WordPressPropertyRepository $wordpress) {}

    public function cities(): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->cities()]);
        }

        return response()->json(['Datos' => City::where('active', true)->orderBy('name')->get()]);
    }

    public function neighborhoods(Request $request): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json([
                'Datos' => $this->wordpress->neighborhoods(
                    $request->query('city_id'),
                    $request->query('q')
                ),
            ]);
        }

        $query = Neighborhood::with('city')->where('active', true);
        if ($cityId = $request->query('city_id')) {
            $query->where('city_id', $cityId);
        }
        if ($search = $request->query('q')) {
            $query->where('name', 'like', "%{$search}%");
        }
        return response()->json(['Datos' => $query->orderBy('name')->get()]);
    }

    public function propertyTypes(): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->propertyTypes()]);
        }

        return response()->json(['Datos' => PropertyType::where('active', true)->orderBy('order')->get()]);
    }

    public function transactionTypes(): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->transactionTypes()]);
        }

        return response()->json(['Datos' => TransactionType::where('active', true)->orderBy('order')->get()]);
    }

    public function destinations(): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->destinations()]);
        }

        return response()->json(['Datos' => []]);
    }

    public function features(Request $request): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->features($request->query('group'))]);
        }

        $query = Feature::query();
        if ($group = $request->query('group')) {
            $query->where('group', $group);
        }
        return response()->json(['Datos' => $query->orderBy('group')->orderBy('order')->get()]);
    }
}
