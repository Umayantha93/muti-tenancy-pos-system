<?php

namespace App\Http\Controllers;

use App\Models\ServiceAddon;
use App\Models\ServiceAddonPrice;
use App\Models\ServiceVehicleClass;
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

        $addons = ServiceAddon::query()
            ->with(['inclusions', 'vehiclePrices:id,service_addon_id,service_vehicle_class_id,price,offered'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->moneyJson($addons);
    }

    /**
     * Save how one vehicle type uses one blueprint service. Offered with no price is the default, so it keeps no row.
     */
    public function saveVehicleSetting(Request $request, ServiceAddon $addon, ServiceVehicleClass $service_vehicle_class): JsonResponse
    {
        $this->assertAddonWorkspace($request);
        $data = $request->validate([
            'offered' => ['required', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $price = $data['price'] ?? null;

        if ($data['offered'] && $price === null) {
            ServiceAddonPrice::query()
                ->where('service_addon_id', $addon->id)
                ->where('service_vehicle_class_id', $service_vehicle_class->id)
                ->delete();
        } else {
            ServiceAddonPrice::updateOrCreate(
                ['service_addon_id' => $addon->id, 'service_vehicle_class_id' => $service_vehicle_class->id],
                ['offered' => $data['offered'], 'price' => $price],
            );
        }

        return $this->moneyJson($addon->fresh()->load(['inclusions', 'vehiclePrices:id,service_addon_id,service_vehicle_class_id,price,offered']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertAddonWorkspace($request);
        $data = $this->validated($request, creating: true);
        $included = $data['included_addon_ids'] ?? [];
        unset($data['included_addon_ids']);

        $addon = ServiceAddon::create([
            ...$data,
            'price' => $data['price'] ?? 0,
            'sort_order' => $data['sort_order'] ?? ((int) ServiceAddon::query()->max('sort_order') + 10),
            'active' => $data['active'] ?? true,
            'is_full_service' => false,
        ]);

        $this->syncFullService($addon, (bool) ($data['is_full_service'] ?? false), $included);

        return $this->moneyJson($addon->fresh()->load('inclusions'), 201);
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

        return $this->moneyJson($addon->fresh()->load('inclusions'));
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
            'name' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:255',
                Rule::unique('service_addons', 'name')->where('tenant_id', $tenantId)->ignore($addonId),
            ],
            'price' => [$creating && ! $isGarage ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_full_service' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
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
            ServiceAddon::query()->where('id', '!=', $addon->id)->update(['is_full_service' => false]);
            $addon->update(['is_full_service' => true]);
            if ($includedIds !== null) {
                $addon->inclusions()->sync(array_values(array_unique(array_map('intval', $includedIds))));
            }
        } else {
            $addon->update(['is_full_service' => false]);
            if ($includedIds !== null) {
                $addon->inclusions()->sync([]);
            }
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
