<?php

namespace App\Http\Controllers;

use App\Models\ServiceAddon;
use App\Models\ServiceVehicleClass;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceVehicleClassController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if ($tenant && $tenant->business_type === BusinessTypes::GARAGE) {
            ServiceVehicleClass::ensureDefaultsFor((int) $tenant->id, BusinessTypes::GARAGE);
        }

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
        $data = $this->validated($request, creating: false);
        $service_vehicle_class->update($data);

        return response()->json($service_vehicle_class->fresh());
    }

    public function destroy(Request $request, ServiceVehicleClass $service_vehicle_class): JsonResponse
    {
        $this->assertGarage($request);
        if (ServiceAddon::query()->where('service_vehicle_class_id', $service_vehicle_class->id)->exists()) {
            throw ValidationException::withMessages([
                'service_vehicle_class' => ['Remove or move services for this vehicle type before deleting it.'],
            ]);
        }
        $service_vehicle_class->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertGarage(Request $request): void
    {
        if ($request->user()->tenant?->business_type !== BusinessTypes::GARAGE) {
            throw ValidationException::withMessages([
                'business_type' => ['Vehicle types are only available for garages.'],
            ]);
        }
    }
}
