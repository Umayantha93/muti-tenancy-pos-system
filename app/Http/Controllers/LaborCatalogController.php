<?php

namespace App\Http\Controllers;

use App\Models\LaborCategory;
use App\Models\LaborItem;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LaborCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if ($tenant?->business_type === BusinessTypes::GARAGE) {
            LaborCategory::seedDefaultsFor((int) $tenant->id);
        }

        $categories = LaborCategory::query()
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->moneyJson($categories);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->assertGarage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $category = LaborCategory::create([
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? ((int) LaborCategory::query()->max('sort_order') + 10),
        ]);

        return $this->moneyJson($category->load('items'), 201);
    }

    public function updateCategory(Request $request, LaborCategory $labor_category): JsonResponse
    {
        $this->assertGarage($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $labor_category->update($data);

        return $this->moneyJson($labor_category->fresh()->load('items'));
    }

    public function destroyCategory(Request $request, LaborCategory $labor_category): JsonResponse
    {
        $this->assertGarage($request);
        $labor_category->delete();

        return response()->json(null, 204);
    }

    public function storeItem(Request $request, LaborCategory $labor_category): JsonResponse
    {
        $this->assertGarage($request);
        $data = $this->itemPayload($request, creating: true);

        $item = $labor_category->items()->create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? ((int) $labor_category->items()->max('sort_order') + 10),
            'active' => $data['active'] ?? true,
        ]);

        return $this->moneyJson($item, 201);
    }

    public function updateItem(Request $request, LaborItem $labor_item): JsonResponse
    {
        $this->assertGarage($request);
        $labor_item->update($this->itemPayload($request, creating: false));

        return $this->moneyJson($labor_item->fresh());
    }

    public function destroyItem(Request $request, LaborItem $labor_item): JsonResponse
    {
        $this->assertGarage($request);
        $labor_item->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'hourly_rate' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'standard_hours' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0'],
            'standard_price' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['standard_price']) && isset($data['hourly_rate']) && (float) $data['hourly_rate'] > 0 && ! isset($data['standard_hours'])) {
            $data['standard_hours'] = round((float) $data['standard_price'] / (float) $data['hourly_rate'], 2);
        }
        unset($data['standard_price']);

        return $data;
    }

    private function assertGarage(Request $request): void
    {
        if ($request->user()->tenant?->business_type !== BusinessTypes::GARAGE) {
            throw ValidationException::withMessages([
                'business_type' => ['Repair labor catalog is only available for garages.'],
            ]);
        }
    }
}
