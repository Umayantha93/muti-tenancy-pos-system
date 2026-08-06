<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SuperAdminTenantController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $signups = Tenant::withTrashed()->oldest()->get(['created_at'])
            ->groupBy(fn (Tenant $tenant) => $tenant->created_at->format('Y-m'))
            ->map(fn ($tenants, $period) => ['period' => $period, 'total' => $tenants->count()])
            ->values();

        return response()->json([
            'total_tenants' => Tenant::withTrashed()->count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
            'inactive_tenants' => Tenant::where('status', 'inactive')->count(),
            'total_users' => User::whereNotNull('tenant_id')->count(),
            'signups' => $signups,
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $type = $request->string('business_type')->toString() ?: null;
        $keys = BusinessTypes::featuresForType($type ?: null);

        return response()->json([
            'business_types' => BusinessTypes::all(),
            'defaults' => $type ? BusinessTypes::defaults($type) : [],
            'features' => Feature::query()
                ->when($type, fn ($query) => $query->whereIn('key', $keys))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'matrix' => BusinessTypes::featureMatrix(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(Tenant::withCount('users')->with('features')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('business_name', 'like', '%'.$request->string('search').'%')
                ->orWhere('owner_email', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $features = $this->normalizeFeatures($request);
        $phones = $this->normalizePhones($request);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', Rule::in(BusinessTypes::all())],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_phone' => ['required', 'regex:/^[0-9+() -]{7,20}$/'],
            'owner_email' => ['required', 'email', 'unique:users,email'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'password' => ['required', 'string', 'min:8'],
            'plan' => ['nullable', 'string', Rule::in(BusinessTypes::plans())],
            'payment_plan' => ['required', Rule::in(BusinessTypes::paymentPlans())],
            'plan_amount' => ['required', 'numeric', 'min:0'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'exists:features,key'],
        ]);
        $data['features'] = $features ?: ($data['features'] ?? null);
        $data['plan'] = $data['plan'] ?? BusinessTypes::defaultPlan($data['business_type']);
        $data['payment_plan'] = $data['payment_plan'] ?? 'monthly';
        $data['owner_phones'] = $phones['owner_phones'];
        $data['contact_phones'] = $phones['contact_phones'];
        $data['owner_phone'] = $phones['owner_phone'];
        $data['contact_phone'] = $phones['contact_phone'] ?: $phones['owner_phone'];
        $data['contact_email'] = $data['contact_email'] ?: $data['owner_email'];

        $tenant = DB::transaction(function () use ($data, $request) {
            $payload = collect($data)->except(['password', 'features', 'logo'])->all();
            $tenant = Tenant::create([...$payload, 'status' => 'active']);
            if ($request->hasFile('logo')) {
                $tenant->update(['logo' => $request->file('logo')->store('tenants', 'public')]);
            }
            User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($data['password']),
                'role' => 'business_owner',
                'status' => 'active',
            ]);
            $allowed = BusinessTypes::defaults($data['business_type']);
            $requested = $data['features'] ?? $allowed;
            $featureIds = Feature::whereIn('key', array_values(array_intersect($requested, $allowed)))->pluck('id');
            $tenant->features()->sync($featureIds->mapWithKeys(fn ($id) => [$id => ['is_enabled' => true]]));
            $this->audit($request, 'tenant.created', $tenant, ['business_name' => $tenant->business_name]);

            return $tenant;
        });

        return response()->json($tenant->load(['users', 'features']), 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json($tenant->load(['features', 'users'])->loadCount('users'));
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $phones = $this->normalizePhones($request, false);
        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:255'],
            'business_type' => ['sometimes', Rule::in(BusinessTypes::all())],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'owner_phone' => ['sometimes', 'regex:/^[0-9+() -]{7,20}$/'],
            'owner_email' => ['sometimes', 'email'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'plan' => ['nullable', 'string', Rule::in(BusinessTypes::plans())],
            'payment_plan' => ['sometimes', Rule::in(BusinessTypes::paymentPlans())],
            'plan_amount' => ['nullable', 'numeric', 'min:0'],
            'logo' => ['nullable', 'image', 'max:5120'],
        ]);
        $payload = collect($data)->except('logo')->all();
        if ($phones['owner_phones'] !== null) {
            $payload['owner_phones'] = $phones['owner_phones'];
            $payload['owner_phone'] = $phones['owner_phone'];
        }
        if ($phones['contact_phones'] !== null) {
            $payload['contact_phones'] = $phones['contact_phones'];
            $payload['contact_phone'] = $phones['contact_phone'];
        }
        $tenant->update($payload);
        if ($request->hasFile('logo')) {
            if ($tenant->logo) {
                Storage::disk('public')->delete($tenant->logo);
            }
            $tenant->update(['logo' => $request->file('logo')->store('tenants', 'public')]);
        }
        $this->audit($request, 'tenant.updated', $tenant, $payload);

        return response()->json($tenant->refresh());
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $tenant->update(['status' => 'inactive']);
        $tenant->users()->update(['status' => 'inactive']);
        $tenant->delete();
        $this->audit($request, 'tenant.deleted', $tenant);

        return response()->json(null, 204);
    }

    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->status($request, $tenant, 'active');
    }

    public function deactivate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->status($request, $tenant, 'inactive');
    }

    public function features(Request $request, Tenant $tenant): JsonResponse
    {
        $keys = BusinessTypes::featuresForType($tenant->business_type);

        return response()->json([
            'available' => Feature::query()->whereIn('key', $keys)->orderBy('sort_order')->orderBy('name')->get(),
            'enabled' => $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key'),
            'business_type' => $tenant->business_type,
        ]);
    }

    public function updateFeatures(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['features' => ['required', 'array'], 'features.*' => ['boolean']]);
        $allowed = BusinessTypes::defaults($tenant->business_type);
        $features = Feature::whereIn('key', array_keys($data['features']))
            ->whereIn('key', $allowed)
            ->get();
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [
            $feature->id => ['is_enabled' => (bool) $data['features'][$feature->key]],
        ]));
        $this->audit($request, 'tenant.features_updated', $tenant, $data['features']);

        return $this->features($request, $tenant);
    }

    public function users(Tenant $tenant): JsonResponse
    {
        return response()->json($tenant->users()->with('permissions')->get());
    }

    public function storeUser(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'role' => ['required', Rule::in(['business_owner', 'staff'])],
        ]);
        $user = $tenant->users()->create([...$data, 'password' => Hash::make($data['password']), 'status' => 'active']);
        $this->audit($request, 'tenant.user_created', $tenant, ['user_id' => $user->id]);

        return response()->json($user, 201);
    }

    private function status(Request $request, Tenant $tenant, string $status): JsonResponse
    {
        $tenant->update(['status' => $status]);
        $tenant->users()->each(fn (User $user) => $user->tokens()->delete());
        $this->audit($request, "tenant.{$status}", $tenant);

        return response()->json($tenant->refresh());
    }

    private function normalizeFeatures(Request $request): ?array
    {
        $features = $request->input('features');
        if (is_string($features)) {
            $decoded = json_decode($features, true);
            $features = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $features)));
            $request->merge(['features' => $features]);
        }

        return is_array($features) ? $features : null;
    }

    /**
     * @return array{owner_phone: ?string, contact_phone: ?string, owner_phones: ?array, contact_phones: ?array}
     */
    private function normalizePhones(Request $request, bool $requiredOwner = true): array
    {
        $ownerPhones = $this->decodePhoneList($request->input('owner_phones'));
        $contactPhones = $this->decodePhoneList($request->input('contact_phones'));

        if ($ownerPhones === null && $request->filled('owner_phone')) {
            $ownerPhones = [['label' => 'Primary', 'number' => $request->string('owner_phone')->toString()]];
        }
        if ($contactPhones === null && $request->filled('contact_phone')) {
            $contactPhones = [['label' => 'Business', 'number' => $request->string('contact_phone')->toString()]];
        }

        if ($requiredOwner) {
            $request->merge([
                'owner_phone' => $ownerPhones[0]['number'] ?? $request->input('owner_phone'),
                'contact_phone' => $contactPhones[0]['number'] ?? $request->input('contact_phone'),
            ]);
        } elseif ($ownerPhones) {
            $request->merge(['owner_phone' => $ownerPhones[0]['number']]);
        }
        if ($contactPhones) {
            $request->merge(['contact_phone' => $contactPhones[0]['number']]);
        }

        foreach (array_merge($ownerPhones ?? [], $contactPhones ?? []) as $entry) {
            if (! preg_match('/^[0-9+() -]{7,20}$/', $entry['number'] ?? '')) {
                abort(response()->json(['message' => 'Each phone number must be 7–20 digits with optional + ( ) -.', 'errors' => ['phones' => ['Invalid phone number.']]], 422));
            }
        }

        return [
            'owner_phone' => $ownerPhones[0]['number'] ?? $request->input('owner_phone'),
            'contact_phone' => $contactPhones[0]['number'] ?? $request->input('contact_phone'),
            'owner_phones' => $ownerPhones,
            'contact_phones' => $contactPhones,
        ];
    }

    /**
     * @return list<array{label: string, number: string}>|null
     */
    private function decodePhoneList(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($value)) {
            return null;
        }

        $list = [];
        foreach (array_slice($value, 0, 5) as $index => $item) {
            if (is_string($item)) {
                $number = trim($item);
                $label = $index === 0 ? 'Primary' : 'Phone '.($index + 1);
            } else {
                $number = trim((string) ($item['number'] ?? ''));
                $label = trim((string) ($item['label'] ?? '')) ?: ($index === 0 ? 'Primary' : 'Phone '.($index + 1));
            }
            if ($number === '') {
                continue;
            }
            $list[] = ['label' => $label, 'number' => $number];
        }

        return $list;
    }

    private function audit(Request $request, string $action, Tenant $tenant, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => $action,
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
