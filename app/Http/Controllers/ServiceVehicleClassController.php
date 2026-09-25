<?php

namespace App\Http\Controllers;

use App\Models\ServiceVehicleClass;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle categories the owner types in (Car, SUV, Tata bus…). Staff pick one on each service job card.
 */
class ServiceVehicleClassController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = ServiceVehicleClass::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertGarage($request);
        $data = $this->validated($request, creating: true);

        $row = ServiceVehicleClass::create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? ((int) ServiceVehicleClass::query()->max('sort_order') + 10),
            'active' => $data['active'] ?? true,
        ]);

        return response()->json($row, 201);
    }

    public function update(Request $request, ServiceVehicleClass $service_vehicle_class): JsonResponse
    {
        $this->assertGarage($request);
        $data = $this->validated($request, creating: false, id: $service_vehicle_class->id);
        $service_vehicle_class->update($data);

        return response()->json($service_vehicle_class->fresh());
    }

    public function destroy(Request $request, ServiceVehicleClass $service_vehicle_class): JsonResponse
    {
        $this->assertGarage($request);
        $service_vehicle_class->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating, ?int $id = null): array
    {
        return $request->validate([
            'name' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:255',
                Rule::unique('service_vehicle_classes', 'name')->where('tenant_id', $request->user()->tenant_id)->ignore($id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertGarage(Request $request): void
    {
        if ($request->user()->tenant?->business_type !== BusinessTypes::GARAGE) {
            throw ValidationException::withMessages([
                'business_type' => ['Vehicle categories are only available for garages.'],
            ]);
        }
    }
}
