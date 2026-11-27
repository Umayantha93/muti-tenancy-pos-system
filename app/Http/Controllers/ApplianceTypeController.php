<?php

namespace App\Http\Controllers;

use App\Models\ApplianceType;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplianceTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->assertAcShop($request);
        ApplianceType::seedDefaultsFor((int) $request->user()->tenant_id);

        $rows = ApplianceType::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'built_in', 'sort_order']);

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertAcShop($request);
        $tenantId = (int) $request->user()->tenant_id;
        ApplianceType::seedDefaultsFor($tenantId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);
        $name = trim($data['name']);
        $key = ApplianceType::keyFromName($name);

        $existing = ApplianceType::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($name, $key) {
                $query->where('key', $key)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
            })
            ->first();
        if ($existing) {
            return response()->json($existing);
        }

        $row = ApplianceType::create([
            'key' => $key,
            'name' => $name,
            'built_in' => false,
            'sort_order' => ((int) ApplianceType::query()->max('sort_order')) + 10,
        ]);

        return response()->json($row, 201);
    }

    private function assertAcShop(Request $request): void
    {
        $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? ''));
        if ($type !== BusinessTypes::AC_SERVICE) {
            throw ValidationException::withMessages([
                'business_type' => ['Appliance types are only for A/C repair shops.'],
            ]);
        }
        abort_unless($request->user()->canAccessFeature('admit_appliance'), 403, 'Appliance admissions are not enabled for this account.');
    }
}
