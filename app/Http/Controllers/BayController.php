<?php

namespace App\Http\Controllers;

use App\Models\Bay;
use App\Support\BranchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BayController extends Controller
{
    public function index(): JsonResponse
    {
        Bay::ensureDefaults();

        $bays = Bay::query()
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $bays]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        $bay = Bay::create([
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? (int) Bay::query()->max('sort_order') + 1,
            'active' => true,
        ]);

        return response()->json($bay, 201);
    }

    public function update(Request $request, Bay $bay): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $bay->update($data);

        return response()->json($bay->refresh());
    }

    public function destroy(Bay $bay): JsonResponse
    {
        $bay->update(['active' => false]);

        return response()->json($bay->refresh());
    }
}
