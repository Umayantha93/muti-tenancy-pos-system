<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\LaborCategory;
use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Models\TenantFeePayment;
use App\Models\User;
use App\Services\ImprovmxMailService;
use App\Support\BusinessTypes;
use App\Support\PaintStockDefaults;
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
            'optional' => $type ? BusinessTypes::optionalFeatures($type) : [],
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
        $year = now()->year;
        $month = now()->month;

        $paginator = Tenant::withCount('users')->with('features')
            ->withExists([
                'feePayments as current_month_paid' => fn ($query) => $query
                    ->where('year', $year)
                    ->where('month', $month),
            ])
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('business_name', 'like', '%'.$request->string('search').'%')
                ->orWhere('owner_email', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()->paginate(min(100, max(1, $request->integer('per_page', 20))));

        $paginator->getCollection()->transform(function (Tenant $tenant) {
            $tenant->current_month_paid = (bool) $tenant->current_month_paid;

            return $tenant;
        });

        return response()->json($paginator);
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
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:32'],
            'vat_registered' => ['sometimes', 'boolean'],
            'sscl_registered' => ['sometimes', 'boolean'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sscl_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'plan' => ['nullable', 'string', Rule::in(BusinessTypes::plans())],
            'payment_plan' => ['required', Rule::in(BusinessTypes::paymentPlans())],
            'plan_amount' => ['required', 'numeric', 'min:0'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'exists:features,key'],
            'demo_access' => ['sometimes', 'boolean'],
            'demo_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $data['features'] = $features ?: ($data['features'] ?? null);
        $data['plan'] = $data['plan'] ?? BusinessTypes::defaultPlan($data['business_type']);
        $data['payment_plan'] = $data['payment_plan'] ?? 'monthly';
        $data['owner_phones'] = $phones['owner_phones'];
        $data['contact_phones'] = $phones['contact_phones'];
        $data['owner_phone'] = $phones['owner_phone'];
        $data['contact_phone'] = $phones['contact_phone'] ?: $phones['owner_phone'];
        $data['contact_email'] = ($data['contact_email'] ?? null) ?: $data['owner_email'];

        $tenant = DB::transaction(function () use ($data, $request) {
            $payload = collect($data)->except(['password', 'features', 'logo', 'demo_access', 'demo_days'])->all();
            $demo = $request->boolean('demo_access');
            $tenant = Tenant::create([
                ...$payload,
                'status' => 'active',
                'demo_ends_at' => $demo ? now()->addDays((int) ($data['demo_days'] ?? 21))->endOfDay() : null,
            ]);
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
            $allowed = BusinessTypes::featuresForType($data['business_type']);
            $requested = $data['features'] ?? BusinessTypes::defaults($data['business_type']);
            $featureIds = Feature::whereIn('key', array_values(array_intersect($requested, $allowed)))->pluck('id');
            $tenant->features()->sync($featureIds->mapWithKeys(fn ($id) => [$id => ['is_enabled' => true]]));
            $this->audit($request, 'tenant.created', $tenant, ['business_name' => $tenant->business_name]);

            if (BusinessTypes::usesVehicleJobs($data['business_type'])) {
                ServiceAddon::seedDefaultsFor((int) $tenant->id, $data['business_type']);
            }
            if (BusinessTypes::usesLaborCatalog($data['business_type'])) {
                LaborCategory::seedDefaultsFor((int) $tenant->id, $data['business_type']);
            }
            if ($data['business_type'] === BusinessTypes::PAINT) {
                PaintStockDefaults::seedFor((int) $tenant->id);
            }

            return $tenant;
        });

        $emailed = app(ImprovmxMailService::class)->sendTenantWelcomeSafely(
            $data['owner_email'],
            $data['owner_name'],
            $tenant->business_name,
            $data['owner_email'],
            $data['password'],
        );

        return response()->json(array_merge(
            $tenant->load(['users', 'features'])->toArray(),
            ['welcome_email_sent' => $emailed],
        ), 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->makeVisible(['dual_financial_view_enabled']);
        $payload = $tenant->load(['features', 'users'])->loadCount('users');
        $payload->users->each(fn (User $user) => $user->makeVisible(['is_secondary_view']));
        $payload->setAttribute(
            'current_month_paid',
            $tenant->feePayments()
                ->where('year', now()->year)
                ->where('month', now()->month)
                ->exists()
        );

        return response()->json($payload);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $phones = $this->normalizePhones($request, false);
        $owner = $tenant->users()
            ->where('role', 'business_owner')
            ->where(fn ($query) => $query->where('is_secondary_view', false)->orWhereNull('is_secondary_view'))
            ->orderBy('id')
            ->first();

        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:255'],
            'business_type' => ['sometimes', Rule::in(BusinessTypes::all())],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'owner_phone' => ['sometimes', 'regex:/^[0-9+() -]{7,20}$/'],
            'owner_email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($owner?->id)],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:32'],
            'vat_registered' => ['sometimes', 'boolean'],
            'sscl_registered' => ['sometimes', 'boolean'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sscl_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
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

        if ($owner) {
            $ownerPayload = [];
            if (array_key_exists('owner_name', $payload)) {
                $ownerPayload['name'] = $payload['owner_name'];
            }
            if (array_key_exists('owner_email', $payload)) {
                $ownerPayload['email'] = $payload['owner_email'];
            }
            if ($ownerPayload !== []) {
                $owner->update($ownerPayload);
            }
        }

        $this->audit($request, 'tenant.updated', $tenant, $payload);

        return response()->json($tenant->refresh()->makeVisible(['dual_financial_view_enabled']));
    }

    public function feePayments(Tenant $tenant): JsonResponse
    {
        $payments = $tenant->feePayments()
            ->with('marker:id,name,email')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn (TenantFeePayment $payment) => [
                'id' => $payment->id,
                'year' => $payment->year,
                'month' => $payment->month,
                'period' => sprintf('%04d-%02d', $payment->year, $payment->month),
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at,
                'notes' => $payment->notes,
                'marked_by' => $payment->marker?->only(['id', 'name', 'email']),
            ]);

        return response()->json([
            'current_month_paid' => $tenant->feePayments()
                ->where('year', now()->year)
                ->where('month', now()->month)
                ->exists(),
            'payments' => $payments,
        ]);
    }

    public function updateFeePayment(Request $request, Tenant $tenant, int $year, int $month): JsonResponse
    {
        abort_unless($tenant->payment_plan === 'monthly', 422, 'Fee payments apply only to monthly plans.');
        abort_unless($month >= 1 && $month <= 12, 422, 'Month must be between 1 and 12.');
        abort_unless($year >= 2000 && $year <= 2100, 422, 'Year is out of range.');

        $data = $request->validate([
            'paid' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['paid']) {
            abort_if($tenant->plan_amount === null, 422, 'Set a plan amount before marking the fee as paid.');

            $payment = TenantFeePayment::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'year' => $year,
                    'month' => $month,
                ],
                [
                    'amount' => $tenant->plan_amount,
                    'paid_at' => now(),
                    'marked_by' => $request->user()->id,
                    'notes' => $data['notes'] ?? null,
                ]
            );
            $this->audit($request, 'tenant.fee_marked_paid', $tenant, [
                'year' => $year,
                'month' => $month,
                'amount' => $payment->amount,
            ]);
        } else {
            TenantFeePayment::query()
                ->where('tenant_id', $tenant->id)
                ->where('year', $year)
                ->where('month', $month)
                ->delete();
            $this->audit($request, 'tenant.fee_marked_unpaid', $tenant, [
                'year' => $year,
                'month' => $month,
            ]);
        }

        return $this->feePayments($tenant);
    }

    public function updateDualFinancialView(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];
        $secondary = $tenant->users()->where('is_secondary_view', true)->first();

        if ($enabled) {
            if (! $secondary) {
                $creds = $request->validate([
                    'secondary_name' => ['required', 'string', 'max:255'],
                    'secondary_email' => ['required', 'email', 'unique:users,email'],
                    'secondary_password' => ['required', 'string', 'min:8'],
                ]);

                $secondary = $tenant->users()->create([
                    'name' => $creds['secondary_name'],
                    'email' => $creds['secondary_email'],
                    'password' => Hash::make($creds['secondary_password']),
                    'role' => 'business_owner',
                    'status' => 'active',
                    'is_secondary_view' => true,
                ]);
                $this->audit($request, 'tenant.secondary_user_created', $tenant, ['user_id' => $secondary->id]);
            } else {
                $secondary->update(['status' => 'active']);
                $secondary->tokens()->delete();
            }

            $tenant->update(['dual_financial_view_enabled' => true]);
            $this->audit($request, 'tenant.dual_financial_view_enabled', $tenant, [
                'secondary_user_id' => $secondary->id,
            ]);
        } else {
            $tenant->update(['dual_financial_view_enabled' => false]);
            $tenant->users()->where('is_secondary_view', true)->each(function (User $user) {
                $user->update(['status' => 'inactive']);
                $user->tokens()->delete();
            });
            $this->audit($request, 'tenant.dual_financial_view_disabled', $tenant);
        }

        $tenant->refresh()->makeVisible(['dual_financial_view_enabled']);
        $tenant->load(['users']);
        $tenant->users->each(fn (User $user) => $user->makeVisible(['is_secondary_view']));

        return response()->json([
            'tenant' => $tenant,
            'secondary_user' => $tenant->users->firstWhere('is_secondary_view', true)?->makeVisible(['is_secondary_view']),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        DB::transaction(function () use ($tenant, $request) {
            $tenant->update(['status' => 'inactive']);
            $tenant->users()->each(function (User $user) {
                $user->update(['status' => 'inactive']);
                $user->tokens()->delete();
            });
            $tenant->delete();
            $this->audit($request, 'tenant.deleted', $tenant, [
                'business_name' => $tenant->business_name,
            ]);
        });

        return response()->json(null, 204);
    }

    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->status($request, $tenant, 'active', clearDemo: true);
    }

    public function deactivate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->status($request, $tenant, 'inactive');
    }

    public function grantDemo(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $days = (int) ($data['days'] ?? 21);
        $tenant->update([
            'status' => 'active',
            'demo_ends_at' => now()->addDays($days)->endOfDay(),
        ]);
        $this->audit($request, 'tenant.demo_granted', $tenant, ['days' => $days, 'demo_ends_at' => $tenant->demo_ends_at]);

        return response()->json($tenant->refresh());
    }

    public function features(Request $request, Tenant $tenant): JsonResponse
    {
        $keys = BusinessTypes::featuresForType($tenant->business_type);

        return response()->json([
            'available' => Feature::query()->whereIn('key', $keys)->orderBy('sort_order')->orderBy('name')->get(),
            'enabled' => $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key'),
            'optional' => BusinessTypes::optionalFeatures($tenant->business_type),
            'business_type' => $tenant->business_type,
        ]);
    }

    public function updateFeatures(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['features' => ['required', 'array'], 'features.*' => ['boolean']]);
        $allowed = BusinessTypes::featuresForType($tenant->business_type);
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
        $users = $tenant->users()->with('permissions')->get();
        $users->each(fn (User $user) => $user->makeVisible(['is_secondary_view']));

        return response()->json($users);
    }

    public function storeUser(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'role' => ['required', Rule::in(['business_owner', 'staff'])],
            'is_secondary_view' => ['sometimes', 'boolean'],
        ]);

        $isSecondary = (bool) ($data['is_secondary_view'] ?? false);
        if ($isSecondary) {
            abort_unless($tenant->dual_financial_view_enabled, 422, 'Enable dual financial view before creating a secondary login.');
            abort_if($tenant->users()->where('is_secondary_view', true)->exists(), 422, 'This tenant already has a secondary login.');
        }

        $user = $tenant->users()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $isSecondary ? 'business_owner' : $data['role'],
            'status' => 'active',
            'is_secondary_view' => $isSecondary,
        ]);
        $this->audit($request, $isSecondary ? 'tenant.secondary_user_created' : 'tenant.user_created', $tenant, ['user_id' => $user->id]);

        return response()->json($user->makeVisible(['is_secondary_view']), 201);
    }

    private function status(Request $request, Tenant $tenant, string $status, bool $clearDemo = false): JsonResponse
    {
        $payload = ['status' => $status];
        if ($clearDemo && $status === 'active') {
            $payload['demo_ends_at'] = null;
        }
        $tenant->update($payload);
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

    private function audit(Request $request, string $action, ?Tenant $tenant, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'subject_type' => Tenant::class,
            'subject_id' => $tenant?->id ?? ($metadata['tenant_id'] ?? null),
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
