<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TenantProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->tenant);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 404);

        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'owner_phone' => ['sometimes', 'regex:/^[0-9+() -]{7,20}$/'],
            'owner_email' => ['sometimes', 'email'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('logo')) {
            if ($tenant->logo) {
                Storage::disk('public')->delete($tenant->logo);
            }
            $data['logo'] = $request->file('logo')->store('tenants', 'public');
        }

        $tenant->update($data);

        return response()->json($tenant->refresh());
    }
}
