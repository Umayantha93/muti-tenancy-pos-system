<?php

namespace App\Http\Controllers;

use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
            'bill_prefix' => ['nullable', 'string', 'max:12'],
            'lock_bill_numbers' => ['sometimes', 'boolean'],
        ]);

        if ($request->hasFile('logo')) {
            if ($tenant->logo) {
                Storage::disk('public')->delete($tenant->logo);
            }
            $data['logo'] = $request->file('logo')->store('tenants', 'public');
        }

        $lockRequested = $request->boolean('lock_bill_numbers');
        unset($data['lock_bill_numbers']);

        if ($tenant->bill_number_locked_at) {
            unset($data['bill_prefix']);
        } elseif (array_key_exists('bill_prefix', $data)) {
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $data['bill_prefix']) ?? '');
            $data['bill_prefix'] = $normalized !== '' ? substr($normalized, 0, 12) : null;
        }

        if ($lockRequested) {
            if (! in_array($tenant->business_type, [BusinessTypes::GARAGE, BusinessTypes::PAINT], true)) {
                throw ValidationException::withMessages([
                    'bill_prefix' => 'Short bill numbers are available for garage and paint shops.',
                ]);
            }
            if ($tenant->bill_number_locked_at) {
                throw ValidationException::withMessages([
                    'bill_prefix' => 'Bill numbering is already locked. Ask super-admin to reset it.',
                ]);
            }
            $prefix = $data['bill_prefix'] ?? $tenant->normalizedBillPrefix();
            if (! $prefix) {
                throw ValidationException::withMessages([
                    'bill_prefix' => 'Enter a short prefix (letters and numbers) before locking.',
                ]);
            }
            $data['bill_prefix'] = $prefix;
            $data['bill_number_locked_at'] = now();
            if ((int) $tenant->bill_sequence < 0) {
                $data['bill_sequence'] = 0;
            }
        }

        $tenant->update($data);

        return response()->json($tenant->refresh());
    }
}
