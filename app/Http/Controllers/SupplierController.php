<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Supplier::query()
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Supplier::create($this->validated($request)), 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return response()->json($supplier->refresh());
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => [$supplier ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
