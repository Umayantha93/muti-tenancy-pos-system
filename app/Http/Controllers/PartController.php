<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ->orWhere('sku', 'like', '%'.$request->string('search').'%')
                ->orWhere('brand', 'like', '%'.$request->string('search').'%')))
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
        $part = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $part = Part::create($data);
            $this->storeImages($request, $part);
            $this->recordPurchaseExpense(
                $request,
                $part,
                (int) ($data['stock_qty'] ?? 0),
                (float) ($data['cost_price'] ?? 0),
            );

            return $part->refresh();
        });

        return response()->json($part, 201);
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
        $this->normalizeImageUploads($request);
        $request->validate(['images' => ['required', 'array', 'max:5'], 'images.*' => ['image', 'max:5120']]);
        $this->storeImages($request, $part);

        return response()->json($part->refresh());
    }

    public function restock(Request $request, Part $part): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expense_date' => ['nullable', 'date'],
        ]);

        [$part, $expense] = DB::transaction(function () use ($data, $part, $request) {
            $unitCost = (float) ($data['unit_cost'] ?? $part->cost_price ?? 0);
            $part->increment('stock_qty', $data['quantity']);
            if ($data['unit_cost'] !== null) {
                $part->update(['cost_price' => $unitCost]);
            }
            $expense = $this->recordPurchaseExpense(
                $request,
                $part->refresh(),
                (int) $data['quantity'],
                $unitCost,
                $data['expense_date'] ?? null,
            );

            return [$part->refresh(), $expense];
        });

        return response()->json(['part' => $part, 'expense' => $expense]);
    }

    private function validated(Request $request, ?Part $part = null): array
    {
        $this->normalizeImageUploads($request);

        $data = $request->validate([
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

        unset($data['images']);

        return $data;
    }

    private function storeImages(Request $request, Part $part): void
    {
        $this->normalizeImageUploads($request);

        if (! $request->hasFile('images')) {
            return;
        }

        $files = $request->file('images');
        $files = is_array($files) ? $files : [$files];

        $images = $part->images ?? [];
        foreach ($files as $image) {
            if (! $image) {
                continue;
            }
            $images[] = $image->store('parts', 'public');
        }
        $part->update(['images' => array_slice($images, 0, 5)]);
    }

    private function normalizeImageUploads(Request $request): void
    {
        // HTML forms may send images[] which Laravel maps to images.
        if ($request->hasFile('images') === false && $request->files->has('images')) {
            $request->files->remove('images');
        }

        if (! $request->hasFile('images')) {
            $request->request->remove('images');
            $request->files->remove('images');

            return;
        }

        $files = $request->file('images');
        if ($files && ! is_array($files)) {
            $request->files->set('images', [$files]);
        }
    }

    private function recordPurchaseExpense(Request $request, Part $part, int $quantity, float $unitCost, ?string $expenseDate = null): ?Expense
    {
        if ($quantity <= 0 || $unitCost <= 0) {
            return null;
        }

        return Expense::create([
            'category' => 'inventory',
            'description' => "Stock purchase: {$part->name} × {$quantity}",
            'amount' => round($unitCost * $quantity, 2),
            'expense_date' => $expenseDate ?? now()->toDateString(),
            'created_by' => $request->user()->id,
        ]);
    }
}
