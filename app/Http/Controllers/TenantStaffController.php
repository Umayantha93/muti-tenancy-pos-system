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
        return response()->json(User::where('tenant_id', $request->user()->tenant_id)->where('role', 'staff')->with('permissions')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'min:8']]);
        $user = User::create([...$data, 'tenant_id' => $request->user()->tenant_id, 'password' => Hash::make($data['password']), 'role' => 'staff', 'status' => 'active']);

        return response()->json($user, 201);
    }

    public function permissions(Request $request, User $user): JsonResponse
    {
        $this->ownedStaff($request, $user);
        return response()->json(['available' => $request->user()->tenant->features()->wherePivot('is_enabled', true)->get(), 'permissions' => $user->permissions]);
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

    private function ownedStaff(Request $request, User $user): void
    {
        abort_unless($user->tenant_id === $request->user()->tenant_id && $user->role === 'staff', 404);
    }
}
