<?php

namespace App\Http\Controllers;

use App\Models\ServiceAddon;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceAddonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if ($tenant && BusinessTypes::usesServiceAddonWorkspace($tenant->business_type)) {
            ServiceAddon::seedDefaultsFor((int) $tenant->id, $tenant->business_type);
        }

        $classId = $request->query('service_vehicle_class_id');
        $addons = ServiceAddon::query()
            ->with(['inclusions', 'vehicleClass:id,name'])
            ->when($classId !== null && $classId !== '', fn ($query) => $query->where('service_vehicle_class_id', (int) $classId))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->moneyJson($addons);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertAddonWorkspace($request);
        $data = $this->validated($request, creating: true);
        $included = $data['included_addon_ids'] ?? [];
        unset($data['included_addon_ids']);

        if ($request->user()->tenant?->business_type === BusinessTypes::GARAGE && empty($data['service_vehicle_class_id'])) {
            throw ValidationException::withMessages([
                'service_vehicle_class_id' => ['Choose a vehicle type for this service.'],
            ]);
        }

        $addon = ServiceAddon::create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? ((int) ServiceAddon::query()->max('sort_order') + 10),
            'active' => $data['active'] ?? true,
            'is_full_service' => false,
        ]);

        $this->syncFullService($addon, (bool) ($data['is_full_service'] ?? false), $included);

        return $this->moneyJson($addon->fresh()->load(['inclusions', 'vehicleClass:id,name']), 201);
    }

    public function update(Request $request, ServiceAddon $addon): JsonResponse
    {
        $this->assertAddonWorkspace($request);
        $data = $this->validated($request, creating: false, addonId: $addon->id);
        $included = array_key_exists('included_addon_ids', $data) ? $data['included_addon_ids'] : null;
        unset($data['included_addon_ids']);

        $makeFull = array_key_exists('is_full_service', $data)
            ? (bool) $data['is_full_service']
            : $addon->is_full_service;
        unset($data['is_full_service']);

        $addon->update($data);
        $this->syncFullService($addon->fresh(), $makeFull, $included);

        return $this->moneyJson($addon->fresh()->load(['inclusions', 'vehicleClass:id,name']));
    }

    public function destroy(Request $request, ServiceAddon $addon): JsonResponse
    {
        $this->assertAddonWorkspace($request);
        $addon->inclusions()->detach();
        $addon->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating, ?int $addonId = null): array
    {
        $tenantId = $request->user()->tenant_id;
        $isGarage = $request->user()->tenant?->business_type === BusinessTypes::GARAGE;

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_full_service' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'service_vehicle_class_id' => [
                $creating && $isGarage ? 'required' : 'nullable',
                'integer',
                Rule::exists('service_vehicle_classes', 'id')->where('tenant_id', $tenantId),
            ],
            'included_addon_ids' => ['nullable', 'array'],
            'included_addon_ids.*' => [
                'integer',
                Rule::exists('service_addons', 'id')->where('tenant_id', $tenantId),
                Rule::notIn([$addonId]),
            ],
        ]);
    }

    /**
     * @param  list<int>|null  $includedIds
     */
    private function syncFullService(ServiceAddon $addon, bool $isFull, ?array $includedIds): void
    {
        if ($isFull) {
            $query = ServiceAddon::query()->where('id', '!=', $addon->id);
            if ($addon->service_vehicle_class_id) {
                $query->where('service_vehicle_class_id', $addon->service_vehicle_class_id);
            } else {
                $query->whereNull('service_vehicle_class_id');
            }
            $query->update(['is_full_service' => false]);
            $addon->update(['is_full_service' => true]);
            if ($includedIds !== null) {
                $this->assertInclusionsSameClass($addon, $includedIds);
                $addon->inclusions()->sync(array_values(array_unique(array_map('intval', $includedIds))));
            }
        } else {
            $addon->update(['is_full_service' => false]);
            if ($includedIds !== null) {
                $addon->inclusions()->sync([]);
            }
        }
    }

    /**
     * @param  list<int>  $includedIds
     */
    private function assertInclusionsSameClass(ServiceAddon $addon, array $includedIds): void
    {
        if ($includedIds === []) {
            return;
        }
        $classId = $addon->service_vehicle_class_id;
        $mismatched = ServiceAddon::query()
            ->whereIn('id', $includedIds)
            ->when(
                $classId === null,
                fn ($query) => $query->whereNotNull('service_vehicle_class_id'),
                fn ($query) => $query->where(function ($inner) use ($classId) {
                    $inner->whereNull('service_vehicle_class_id')
                        ->orWhere('service_vehicle_class_id', '!=', $classId);
                }),
            )
            ->exists();
        if ($mismatched) {
            throw ValidationException::withMessages([
                'included_addon_ids' => ['Full service can only include services from the same vehicle type.'],
            ]);
        }
    }

    private function assertAddonWorkspace(Request $request): void
    {
        $type = (string) $request->user()->tenant?->business_type;
        if (! BusinessTypes::usesServiceAddonWorkspace($type)) {
            throw ValidationException::withMessages([
                'business_type' => ['Service addons are only available for garages and paint shops.'],
            ]);
        }
    }
}
