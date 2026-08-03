<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
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
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', Rule::in(['garage', 'cottage', 'supermarket', 'shop'])],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_phone' => ['required', 'regex:/^[0-9+() -]{7,20}$/'],
            'owner_email' => ['required', 'email', 'unique:users,email'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'password' => ['required', 'string', 'min:8'],
            'plan' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'exists:features,key'],
        ]);
        $data['features'] = $features ?: ($data['features'] ?? null);
        $data['contact_email'] = $data['contact_email'] ?: $data['owner_email'];
        $data['contact_phone'] = $data['contact_phone'] ?: $data['owner_phone'];

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
            $featureIds = Feature::whereIn('key', $data['features'] ?? $this->defaults($data['business_type']))->pluck('id');
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
        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:255'],
            'business_type' => ['sometimes', Rule::in(['garage', 'cottage', 'supermarket', 'shop'])],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'owner_phone' => ['sometimes', 'regex:/^[0-9+() -]{7,20}$/'],
            'owner_email' => ['sometimes', 'email'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'plan' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'max:5120'],
        ]);
        $payload = collect($data)->except('logo')->all();
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

    public function activate(Request $request, Tenant $tenant): JsonResponse { return $this->status($request, $tenant, 'active'); }
    public function deactivate(Request $request, Tenant $tenant): JsonResponse { return $this->status($request, $tenant, 'inactive'); }

    public function features(Tenant $tenant): JsonResponse
    {
        return response()->json(['available' => Feature::all(), 'enabled' => $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key')]);
    }

    public function updateFeatures(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['features' => ['required', 'array'], 'features.*' => ['boolean']]);
        $features = Feature::whereIn('key', array_keys($data['features']))->get();
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [
            $feature->id => ['is_enabled' => (bool) $data['features'][$feature->key]],
        ]));
        $this->audit($request, 'tenant.features_updated', $tenant, $data['features']);

        return $this->features($tenant);
    }

    public function users(Tenant $tenant): JsonResponse { return response()->json($tenant->users()->with('permissions')->get()); }

    public function storeUser(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'], 'role' => ['required', Rule::in(['business_owner', 'staff'])],
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

    private function defaults(string $type): array
    {
        return $type === 'garage'
            ? Feature::pluck('key')->all()
            : ['billing', 'parts_inventory', 'reports', 'balance_sheet'];
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

    private function audit(Request $request, string $action, Tenant $tenant, array $metadata = []): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'tenant_id' => $tenant->id, 'action' => $action,
            'subject_type' => Tenant::class, 'subject_id' => $tenant->id, 'metadata' => $metadata, 'ip_address' => $request->ip()]);
    }
}
