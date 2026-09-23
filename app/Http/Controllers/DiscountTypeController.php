<?php

namespace App\Http\Controllers;

use App\Models\DiscountType;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DiscountTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if ($tenant && in_array($tenant->business_type, [BusinessTypes::GARAGE, BusinessTypes::PAINT], true)) {
            DiscountType::seedDefaultsFor((int) $tenant->id, $tenant->business_type);
        }

        $rows = DiscountType::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->moneyJson($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertWorkspace($request);
        $data = $this->validated($request, creating: true);

        $row = DiscountType::create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? ((int) DiscountType::query()->max('sort_order') + 10),
            'active' => $data['active'] ?? true,
        ]);

        return $this->moneyJson($row, 201);
    }

    public function update(Request $request, DiscountType $discount_type): JsonResponse
    {
        $this->assertWorkspace($request);
        $data = $this->validated($request, creating: false);
        $discount_type->update($data);

        return $this->moneyJson($discount_type->fresh());
    }

    public function destroy(Request $request, DiscountType $discount_type): JsonResponse
    {
        $this->assertWorkspace($request);
        $discount_type->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'mode' => [$creating ? 'required' : 'sometimes', Rule::in([DiscountType::MODE_AMOUNT, DiscountType::MODE_PERCENT])],
            'value' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertWorkspace(Request $request): void
    {
        $type = (string) $request->user()->tenant?->business_type;
        if (! in_array($type, [BusinessTypes::GARAGE, BusinessTypes::PAINT], true)) {
            throw ValidationException::withMessages([
                'business_type' => ['Discount types are only available for garages and paint shops.'],
            ]);
        }
    }
}
