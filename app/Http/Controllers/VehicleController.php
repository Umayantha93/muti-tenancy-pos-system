<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Vehicle::with('customer')->withCount('bills')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('number_plate', 'like', '%'.$request->string('search').'%')
                ->orWhere('chassis_number', 'like', '%'.$request->string('search').'%')))
            ->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Vehicle::create($this->validated($request)), 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        return response()->json($vehicle->load(['customer', 'bills']));
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle->update($this->validated($request, $vehicle));
        return response()->json($vehicle->refresh());
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->delete();
        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $data = $request->validate([
            'customer_id' => [$vehicle ? 'sometimes' : 'required', Rule::exists('customers', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'number_plate' => [$vehicle ? 'sometimes' : 'required', 'string', 'max:30'],
            'chassis_number' => ['nullable', 'string', 'max:100', Rule::unique('vehicles')->where('tenant_id', $request->user()->tenant_id)->ignore($vehicle)],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
        ]);

        if (array_key_exists('chassis_number', $data) && ($data['chassis_number'] === null || trim((string) $data['chassis_number']) === '')) {
            $data['chassis_number'] = null;
        } elseif (! empty($data['chassis_number'])) {
            $data['chassis_number'] = strtoupper(trim($data['chassis_number']));
        }

        if (! empty($data['number_plate'])) {
            $data['number_plate'] = strtoupper(trim($data['number_plate']));
        }

        return $data;
    }
}
