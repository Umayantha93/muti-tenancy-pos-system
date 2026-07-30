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

        $user->tokens()->delete();

        return response()->json([
            'token' => $user->createToken('garage-pos')->plainTextToken,
            'user' => $user->load('tenant'),
            'features' => $user->accessibleFeatureKeys(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
