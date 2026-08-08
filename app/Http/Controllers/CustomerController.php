<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Customer::query()
            ->withCount(['vehicles', 'bills'])
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('phone'), fn ($query) => $query->where('phone', 'like', '%'.$request->string('phone').'%'))
            ->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Customer::create($this->validated($request)), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer->load([
            'vehicles',
            'bills' => fn ($query) => $query->with('vehicle')->latest('admission_date')->orderByDesc('id'),
        ])->loadCount(['vehicles', 'bills']));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->validated($request, true));
        return response()->json($customer->refresh());
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();
        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $update = false): array
    {
        return $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone' => [$update ? 'sometimes' : 'required', 'regex:/^[0-9+() -]{7,20}$/'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
