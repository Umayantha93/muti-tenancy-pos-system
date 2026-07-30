<?php

namespace App\Http\Controllers;

use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allowedSorts = ['name', 'brand', 'type', 'model', 'year', 'price', 'stock_qty', 'created_at'];
        $sort = in_array($request->string('sort')->toString(), $allowedSorts, true) ? $request->string('sort') : 'name';
        $direction = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        $parts = Part::query()
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('sku', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('brand'), fn ($query) => $query->where('brand', $request->string('brand')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('model'), fn ($query) => $query->where('model', 'like', '%'.$request->string('model').'%'))
            ->when($request->filled('year'), fn ($query) => $query->where('year', $request->integer('year')))
            ->orderBy($sort, $direction)
            ->paginate($request->integer('per_page', 20));

        return response()->json($parts);
    }

    public function store(Request $request): JsonResponse
    {
        $part = Part::create($this->validated($request));
        $this->storeImages($request, $part);

        return response()->json($part->refresh(), 201);
    }

    public function show(Part $part): JsonResponse
    {
        return response()->json($part);
    }

    public function update(Request $request, Part $part): JsonResponse
    {
        $part->update($this->validated($request, $part));
        $this->storeImages($request, $part);

        return response()->json($part->refresh());
    }

    public function destroy(Part $part): JsonResponse
    {
        foreach ($part->images ?? [] as $image) {
            Storage::disk('public')->delete($image);
        }
        $part->delete();

        return response()->json(null, 204);
    }

    public function image(Request $request, Part $part): JsonResponse
    {
        $request->validate(['images' => ['required', 'array', 'max:5'], 'images.*' => ['image', 'max:5120']]);
        $this->storeImages($request, $part);

        return response()->json($part->refresh());
    }

    private function validated(Request $request, ?Part $part = null): array
    {
        return $request->validate([
            'name' => [$part ? 'sometimes' : 'required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('parts')->where('tenant_id', $request->user()->tenant_id)->ignore($part)],
            'brand' => [$part ? 'sometimes' : 'required', 'string', 'max:100'],
            'type' => [$part ? 'sometimes' : 'required', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'price' => [$part ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => [$part ? 'sometimes' : 'required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['image', 'max:5120'],
        ]);
    }

    private function storeImages(Request $request, Part $part): void
    {
        if (! $request->hasFile('images')) {
            return;
        }

        $images = $part->images ?? [];
        foreach ($request->file('images') as $image) {
            $images[] = $image->store('parts', 'public');
        }
        $part->update(['images' => array_slice($images, 0, 5)]);
    }
}
