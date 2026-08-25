<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TenantStaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(User::where('tenant_id', $request->user()->tenant_id)->where('role', 'staff')->with(['permissions', 'employee'])->get());
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->input('employee_id') === '' || $request->input('employee_id') === '0') {
            $request->merge(['employee_id' => null]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'preset' => ['nullable', Rule::in(['custom', 'shop_floor'])],
        ]);
        abort_if(
            ! empty($data['employee_id']) && User::where('employee_id', $data['employee_id'])->exists(),
            422,
            'That team member already has a staff login.',
        );
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'tenant_id' => $request->user()->tenant_id,
            'employee_id' => $data['employee_id'] ?? null,
            'role' => 'staff',
            'status' => 'active',
        ]);
        if (($data['preset'] ?? 'custom') === 'shop_floor') {
            $keys = ['billing', 'parts_inventory'];
            $enabled = $request->user()->tenant->features()
                ->wherePivot('is_enabled', true)
                ->whereIn('features.key', $keys)
                ->get();
            $user->permissions()->sync($enabled->mapWithKeys(fn (Feature $feature) => [
                $feature->id => ['can_access' => true],
            ]));
        }

        return response()->json($user->load(['permissions', 'employee']), 201);
    }

    public function permissions(Request $request, User $user): JsonResponse
    {
        $this->ownedStaff($request, $user);
        return response()->json([
            'available' => $request->user()->tenant->features()
                ->wherePivot('is_enabled', true)
                ->orderBy('features.sort_order')
                ->orderBy('features.name')
                ->get(),
            'permissions' => $user->permissions,
        ]);
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        $this->ownedStaff($request, $user);
        $data = $request->validate(['permissions' => ['required', 'array'], 'permissions.*' => ['boolean']]);
        $enabled = $request->user()->tenant->features()->wherePivot('is_enabled', true)->whereIn('features.key', array_keys($data['permissions']))->get();
        $user->permissions()->sync($enabled->mapWithKeys(fn (Feature $feature) => [
            $feature->id => ['can_access' => (bool) $data['permissions'][$feature->key]],
        ]));

        return $this->permissions($request, $user);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->ownedStaff($request, $user);
        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();

        return response()->json($user->refresh());
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->ownedStaff($request, $user);
        $user->update(['status' => 'active']);

        return response()->json($user->refresh());
    }

    private function ownedStaff(Request $request, User $user): void
    {
        abort_unless($user->tenant_id === $request->user()->tenant_id && $user->role === 'staff', 404);
    }
}
