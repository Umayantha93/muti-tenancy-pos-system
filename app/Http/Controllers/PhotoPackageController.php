<?php

namespace App\Http\Controllers;

use App\Models\PhotoPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoPackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $packages = PhotoPackage::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
            ->latest()
            ->get();

        return response()->json($packages);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:15'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(PhotoPackage::create($data), 201);
    }

    public function update(Request $request, PhotoPackage $package): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:15'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $package->update($data);

        return response()->json($package->refresh());
    }

    public function destroy(PhotoPackage $package): JsonResponse
    {
        $package->delete();

        return response()->json(null, 204);
    }
}
