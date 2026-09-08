<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SuperAdminUserController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->only(['id', 'name', 'email', 'role', 'status']));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $payload = [];
        if (array_key_exists('name', $data) && filled($data['name'])) {
            $payload['name'] = $data['name'];
        }
        if (array_key_exists('email', $data) && filled($data['email'])) {
            $payload['email'] = $data['email'];
        }
        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }
        abort_if($payload === [], 422, 'Nothing to update.');

        $user->update($payload);
        $this->audit($request, 'super_admin.profile_updated', $user);

        return response()->json($user->refresh()->only(['id', 'name', 'email', 'role', 'status']));
    }

    public function activate(Request $request, User $user): JsonResponse { return $this->status($request, $user, 'active'); }
    public function deactivate(Request $request, User $user): JsonResponse { return $this->status($request, $user, 'inactive'); }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->role === 'super_admin', 422, 'Super admin accounts cannot be removed here.');
        $this->audit($request, 'tenant.user_deleted', $user);
        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }

    private function status(Request $request, User $user, string $status): JsonResponse
    {
        abort_if($user->role === 'super_admin', 422, 'Super admin accounts cannot be managed here.');
        $user->update(['status' => $status]);
        if ($status === 'inactive') $user->tokens()->delete();
        $this->audit($request, "tenant.user_{$status}", $user);

        return response()->json($user->refresh());
    }

    private function audit(Request $request, string $action, User $user): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'tenant_id' => $user->tenant_id, 'action' => $action,
            'subject_type' => User::class, 'subject_id' => $user->id, 'ip_address' => $request->ip()]);
    }
}
