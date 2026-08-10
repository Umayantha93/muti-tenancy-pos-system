<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        $user = \App\Models\User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        if ($user->status !== 'active' || ($user->role !== 'super_admin' && $user->tenant?->status !== 'active')) {
            throw ValidationException::withMessages(['email' => ['This account is inactive.']]);
        }

        if ($user->is_secondary_view && ! $user->tenant?->dual_financial_view_enabled) {
            throw ValidationException::withMessages(['email' => ['This account is inactive.']]);
        }

        return response()->json([
            'token' => $user->createToken('garage-pos')->plainTextToken,
            'user' => $user->load('tenant'),
            'features' => $user->accessibleFeatureKeys(),
        ]);
    }

    public function branding(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = \App\Models\User::with('tenant')->where('email', $data['email'])->first();
        $tenant = $user?->tenant;

        return response()->json([
            'business_name' => $tenant?->business_name,
            'business_type' => $tenant?->business_type,
            'logo_url' => $tenant?->logo_url,
            'address' => $tenant?->address,
            'contact_email' => $tenant?->contact_email ?? $tenant?->owner_email,
            'contact_phone' => $tenant?->contact_phone ?? $tenant?->owner_phone,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
