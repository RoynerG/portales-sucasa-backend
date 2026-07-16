<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\WordPressPropertyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultantController extends Controller
{
    public function __construct(protected WordPressPropertyRepository $wordpress) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->wordpress->enabled()) {
            return response()->json(['Datos' => $this->wordpress->consultants()]);
        }

        $items = Consultant::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();
        return response()->json(['Datos' => $items]);
    }

    public function show(Consultant $consultant): JsonResponse
    {
        return response()->json(['Datos' => [$consultant->load('properties')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'department' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'highlighted_limit' => ['nullable', 'integer', 'min:0'],
            'super_limit' => ['nullable', 'integer', 'min:0'],
        ]);
        $consultant = Consultant::create($data);
        return response()->json(['Datos' => [$consultant]], 201);
    }

    public function update(Request $request, Consultant $consultant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'department' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'highlighted_limit' => ['nullable', 'integer', 'min:0'],
            'super_limit' => ['nullable', 'integer', 'min:0'],
            'active' => ['boolean'],
        ]);
        $consultant->update($data);
        return response()->json(['Datos' => [$consultant]]);
    }
}
