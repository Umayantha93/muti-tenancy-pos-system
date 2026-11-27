<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\LaborCategory;
use App\Models\ServiceAddon;
use App\Models\LaborItem;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BillCalculator;
use App\Support\BranchContext;
use App\Support\BusinessTypes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AcServiceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = $this->ensureTenant();
        $owner = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'business_owner')
            ->first();
        if (! $owner) {
            return;
        }

        Auth::login($owner);
        BranchContext::set((int) Branch::defaultIdFor($tenant->id));

        try {
            if (Bill::query()->where('bill_number', 'like', '%-AC01')->exists()) {
                return;
            }

            $parts = $this->seedStock($tenant);
            $labor = LaborItem::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->get()
                ->keyBy('name')
                ->all();
            $customers = $this->seedCustomers();
            $subjects = $this->seedSubjects($customers);
            $this->seedBills($owner, $customers, $subjects, $parts, $labor);
            $this->seedExpenses($owner);
        } finally {
            Auth::logout();
            BranchContext::clear();
        }
    }

    private function ensureTenant(): Tenant
    {
        $tenant = Tenant::query()->where('owner_email', 'owner@ac.lk')->first();
        if ($tenant) {
            LaborCategory::seedDefaultsFor((int) $tenant->id, BusinessTypes::AC_SERVICE);
        ServiceAddon::seedDefaultsFor((int) $tenant->id, BusinessTypes::AC_SERVICE);

            return $tenant;
        }

        if (Feature::query()->doesntExist()) {
            $this->call(FeatureSeeder::class);
        }

        $tenant = Tenant::query()->create([
            'business_name' => 'Cool Air Services',
            'business_type' => BusinessTypes::AC_SERVICE,
            'owner_name' => 'Nuwan Perera',
            'owner_phone' => '0774102200',
            'owner_phones' => [['label' => 'Primary', 'number' => '0774102200']],
            'owner_email' => 'owner@ac.lk',
            'contact_email' => 'owner@ac.lk',
            'contact_phone' => '0774102200',
            'contact_phones' => [['label' => 'Workshop', 'number' => '0774102200']],
            'address' => 'No. 12, High Level Road, Nugegoda',
            'status' => 'active',
            'plan' => 'ac-pro',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ]);

        $keys = BusinessTypes::defaults(BusinessTypes::AC_SERVICE);
        $tenant->features()->sync(
            Feature::query()->whereIn('key', $keys)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['is_enabled' => true]])
                ->all()
        );

        $branch = Branch::ensureDefault($tenant);
        LaborCategory::seedDefaultsFor((int) $tenant->id, BusinessTypes::AC_SERVICE);
        ServiceAddon::seedDefaultsFor((int) $tenant->id, BusinessTypes::AC_SERVICE);

        User::query()->updateOrCreate(['email' => 'owner@ac.lk'], [
            'tenant_id' => $tenant->id,
            'name' => 'Nuwan Perera',
            'password' => Hash::make('password'),
            'role' => 'business_owner',
            'status' => 'active',
            'home_branch_id' => $branch->id,
            'last_branch_id' => $branch->id,
        ]);

        return $tenant->fresh();
    }

    /**
     * @return array<string, Part>
     */
    private function seedStock(Tenant $tenant): array
    {
        $catalog = [
            ['name' => 'R134a refrigerant', 'brand' => 'Coolgas', 'type' => 'Gas', 'price' => 4500, 'cost_price' => 2800, 'stock_qty' => 40, 'stock_unit' => 'qty', 'description' => '1 kg cylinders'],
            ['name' => 'Split compressor 1.5 ton', 'brand' => 'LG', 'type' => 'Compressor', 'price' => 28500, 'cost_price' => 19200, 'stock_qty' => 6, 'stock_unit' => 'qty', 'description' => 'Rotary compressor'],
            ['name' => 'Outdoor fan motor', 'brand' => 'Samsung', 'type' => 'Motor', 'price' => 8500, 'cost_price' => 5200, 'stock_qty' => 8, 'stock_unit' => 'qty', 'description' => 'Outdoor unit fan'],
            ['name' => 'Run capacitor 35uF', 'brand' => 'Shop stock', 'type' => 'Electrical', 'price' => 1800, 'cost_price' => 750, 'stock_qty' => 24, 'stock_unit' => 'qty', 'description' => 'Compressor capacitor'],
            ['name' => 'Indoor filter', 'brand' => 'Shop stock', 'type' => 'Filter', 'price' => 950, 'cost_price' => 320, 'stock_qty' => 30, 'stock_unit' => 'qty', 'description' => 'Washable mesh filter'],
            ['name' => 'Copper pipe 1/4"', 'brand' => 'Shop stock', 'type' => 'Pipe', 'price' => 1200, 'cost_price' => 700, 'stock_qty' => 50, 'stock_unit' => 'qty', 'description' => 'Per metre'],
        ];

        $parts = [];
        foreach ($catalog as $row) {
            $part = Part::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $row['name']],
                ['tenant_id' => $tenant->id, ...$row],
            );
            $parts[$row['name']] = $part->fresh();
        }

        Supplier::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Coolgas Lanka'],
            [
                'tenant_id' => $tenant->id,
                'phone' => '0112557788',
                'notes' => 'Refrigerant and copper',
                'active' => true,
            ],
        );

        return $parts;
    }

    /**
     * @return array<string, Customer>
     */
    private function seedCustomers(): array
    {
        $people = [
            ['Nimal Perera', '0778112201', 'Nugegoda'],
            ['Amara Silva', '0778112202', 'Maharagama'],
            ['Kasun Fernando', '0778112203', 'Kottawa'],
            ['Ishara Jayasuriya', '0778112204', 'Colombo 05'],
            ['Sanduni Fernando', '0778112205', 'Dehiwala'],
            ['Ruwan Jayawardena', '0778112206', 'Battaramulla'],
        ];
        $customers = [];
        foreach ($people as [$name, $phone, $address]) {
            $customers[$phone] = Customer::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => Auth::user()->tenant_id, 'phone' => $phone],
                ['tenant_id' => Auth::user()->tenant_id, 'name' => $name, 'address' => $address],
            );
        }

        return $customers;
    }

    /**
     * @param  array<string, Customer>  $customers
     * @return array<string, Vehicle>
     */
    private function seedSubjects(array $customers): array
    {
        $tenantId = Auth::user()->tenant_id;
        $vehicles = [
            ['0778112201', 'CAA-4521', 'ACCHS001', 'Toyota', 'Aqua', 2018],
            ['0778112202', 'CAB-8832', 'ACCHS002', 'Honda', 'Vezel', 2019],
            ['0778112203', 'CAC-1109', 'ACCHS003', 'Suzuki', 'WagonR', 2020],
        ];
        $subjects = [];
        foreach ($vehicles as [$phone, $plate, $chassis, $make, $model, $year]) {
            $subjects[$plate] = Vehicle::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'chassis_number' => $chassis],
                [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customers[$phone]->id,
                    'asset_kind' => 'vehicle',
                    'number_plate' => $plate,
                    'chassis_number' => $chassis,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'fault_description' => 'Vehicle air-conditioning',
                ],
            );
        }

        $appliances = [
            ['0778112204', 'SN-LG-4412', 'split_ac', 'LG', 'Dual Cool', 'Not cooling'],
            ['0778112205', 'SN-SS-2208', 'fridge', 'Samsung', 'RT42', 'Warm freezer compartment'],
            ['0778112206', 'SN-WL-1180', 'freezer', 'Walton', 'Chest 300', 'Compressor humming'],
            ['0778112201', 'SN-WD-0901', 'window_ac', 'Abans', 'Window 1 ton', 'Water dripping inside'],
        ];
        foreach ($appliances as [$phone, $serial, $type, $make, $model, $fault]) {
            $subjects[$serial] = Vehicle::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'number_plate' => $serial, 'asset_kind' => 'appliance'],
                [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customers[$phone]->id,
                    'asset_kind' => 'appliance',
                    'appliance_type' => $type,
                    'number_plate' => $serial,
                    'make' => $make,
                    'model' => $model,
                    'fault_description' => $fault,
                ],
            );
        }

        return $subjects;
    }

    /**
     * @param  array<string, Customer>  $customers
     * @param  array<string, Vehicle>  $subjects
     * @param  array<string, Part>  $parts
     * @param  array<string, LaborItem>  $labor
     */
    private function seedBills(User $owner, array $customers, array $subjects, array $parts, array $labor): void
    {
        $calculator = app(BillCalculator::class);
        $jobs = [
            [
                'suffix' => 'AC01', 'date' => now()->subDays(12)->toDateString(),
                'customer' => $customers['0778112201'], 'subject' => $subjects['CAA-4521'],
                'job_kind' => Bill::JOB_KIND_REPAIR, 'service_place' => null,
                'notes' => 'Aqua — compressor seized, gas leak at the condenser',
                'labor' => [['Leak test', 1.0], ['Compressor replacement', 3.0], ['Vacuum and recharge', 1.5]],
                'parts' => [['Split compressor 1.5 ton', 1], ['R134a refrigerant', 1], ['Run capacitor 35uF', 1]],
                'pay' => 'full', 'method' => 'cash', 'close' => true, 'warranty' => 6,
            ],
            [
                'suffix' => 'AC02', 'date' => now()->subDays(9)->toDateString(),
                'customer' => $customers['0778112202'], 'subject' => $subjects['CAB-8832'],
                'job_kind' => Bill::JOB_KIND_REPAIR, 'service_place' => null,
                'notes' => 'Vezel — gas and leak repair',
                'labor' => [['Gas charge', 1.0], ['Filter clean', 0.5]],
                'parts' => [['R134a refrigerant', 1]],
                'pay' => 'full', 'method' => 'card', 'close' => true,
            ],
            [
                'suffix' => 'AC03', 'date' => now()->subDays(4)->toDateString(),
                'customer' => $customers['0778112203'], 'subject' => $subjects['CAC-1109'],
                'job_kind' => Bill::JOB_KIND_REPAIR, 'service_place' => null,
                'notes' => 'WagonR — weak cooling, fan motor noisy',
                'labor' => [['Leak test', 1.0], ['Fan motor replacement', 1.5]],
                'parts' => [['Outdoor fan motor', 1], ['R134a refrigerant', 1]],
                'pay' => 0.5, 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'AC04', 'date' => now()->subDays(3)->toDateString(),
                'customer' => $customers['0778112204'], 'subject' => $subjects['SN-LG-4412'],
                'job_kind' => Bill::JOB_KIND_REPAIR, 'service_place' => 'on_site',
                'notes' => 'Split unit at Colombo 05 — not cooling, on site',
                'labor' => [['Leak test', 1.0], ['Gas charge', 1.0]],
                'parts' => [['R134a refrigerant', 1], ['Copper pipe 1/4"', 3]],
                'pay' => 0, 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'AC05', 'date' => now()->subDays(2)->toDateString(),
                'customer' => $customers['0778112205'], 'subject' => $subjects['SN-SS-2208'],
                'job_kind' => Bill::JOB_KIND_REPAIR, 'service_place' => 'workshop',
                'notes' => 'Samsung fridge in the workshop — warm freezer',
                'labor' => [['PCB repair', 1.5], ['Gas charge', 1.0]],
                'parts' => [['Run capacitor 35uF', 1], ['R134a refrigerant', 1]],
                'pay' => 0.4, 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'AC06', 'date' => now()->subDays(1)->toDateString(),
                'customer' => $customers['0778112206'], 'subject' => $subjects['SN-WL-1180'],
                'job_kind' => Bill::JOB_KIND_SERVICE, 'service_place' => 'on_site',
                'notes' => 'Chest freezer at Battaramulla — pay on collection',
                'labor' => [['Filter clean', 0.5], ['Gas charge', 1.0]],
                'parts' => [['R134a refrigerant', 1]],
                'pay' => 0, 'method' => 'cash', 'close' => false, 'owe_in' => now()->addDays(7)->toDateString(),
            ],
            [
                'suffix' => 'AC07', 'date' => now()->toDateString(),
                'customer' => $customers['0778112201'], 'subject' => $subjects['SN-WD-0901'],
                'job_kind' => Bill::JOB_KIND_REPAIR, 'service_place' => 'workshop',
                'notes' => 'Window unit dripping inside — opened today',
                'labor' => [['Leak test', 1.0]],
                'parts' => [['Indoor filter', 1]],
                'pay' => 0, 'method' => 'cash', 'close' => false,
            ],
        ];

        foreach ($jobs as $job) {
            $this->openJob($owner, $calculator, $parts, $labor, $job);
        }
    }

    /**
     * @param  array<string, Part>  $parts
     * @param  array<string, LaborItem>  $labor
     * @param  array<string, mixed>  $job
     */
    private function openJob(User $owner, BillCalculator $calculator, array $parts, array $labor, array $job): void
    {
        $date = $job['date'];
        $bill = Bill::query()->create([
            'tenant_id' => $owner->tenant_id,
            'branch_id' => BranchContext::id() ?: Branch::defaultIdFor($owner->tenant_id),
            'bill_number' => 'JOB-'.now()->parse($date)->format('Ymd').'-'.$job['suffix'],
            'customer_id' => $job['customer']->id,
            'vehicle_id' => $job['subject']->id,
            'admission_date' => $date,
            'odometer' => $job['subject']->asset_kind === 'vehicle' ? 62000 : null,
            'notes' => $job['notes'],
            'job_kind' => $job['job_kind'],
            'service_place' => $job['service_place'],
            'created_by' => $owner->id,
        ]);

        foreach ($job['labor'] as [$name, $hours]) {
            $item = $labor[$name] ?? null;
            if (! $item) {
                continue;
            }
            $unit = (float) $item->hourly_rate;
            BillItem::query()->create([
                'tenant_id' => $owner->tenant_id,
                'bill_id' => $bill->id,
                'type' => 'labor',
                'labor_item_id' => $item->id,
                'description' => $item->name,
                'quantity' => $hours,
                'unit_price' => $unit,
                'line_total' => round($unit * $hours, 2),
            ]);
        }

        foreach ($job['parts'] as [$name, $qty]) {
            $part = $parts[$name] ?? null;
            if (! $part) {
                continue;
            }
            $part->takeStock((int) $qty, $bill->branch_id);
            $unit = (float) $part->price;
            BillItem::query()->create([
                'tenant_id' => $owner->tenant_id,
                'bill_id' => $bill->id,
                'type' => 'part',
                'part_id' => $part->id,
                'description' => $part->name,
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => round($unit * $qty, 2),
                'purchase_unit_cost' => $part->cost_price,
            ]);
        }

        $bill = $calculator->recalculate($bill);
        if (! empty($job['warranty'])) {
            $bill->update([
                'warranty_months' => $job['warranty'],
                'warranty_starts_on' => $date,
                'warranty_until' => now()->parse($date)->addMonths((int) $job['warranty'])->toDateString(),
            ]);
            $bill = $bill->fresh();
        }

        $pay = $job['pay'];
        $amount = $pay === 'full'
            ? (float) $bill->balance_due
            : round((float) $bill->subtotal * (float) $pay, 2);

        if (! empty($job['owe_in'])) {
            $bill->update([
                'status' => 'owe_in',
                'owe_in_due_date' => $job['owe_in'],
                'updated_by' => $owner->id,
            ]);
            $bill = $bill->fresh();
        }

        if ($amount > 0) {
            BillPayment::query()->create([
                'tenant_id' => $owner->tenant_id,
                'bill_id' => $bill->id,
                'amount' => $amount,
                'method' => $job['method'],
                'paid_at' => now()->parse($date)->setTime(16, 0),
                'received_by' => $owner->id,
            ]);
            $bill = $calculator->recalculate($bill->fresh());
        }

        if (! empty($job['close']) && (float) $bill->balance_due <= 0) {
            $bill->update([
                'status' => 'closed',
                'closed_at' => now()->parse($date)->setTime(17, 30),
                'updated_by' => $owner->id,
            ]);
        }
    }

    private function seedExpenses(User $owner): void
    {
        $rows = [
            ['utilities', 'Workshop electricity', 18600, now()->startOfMonth()->addDays(3)->toDateString()],
            ['inventory', 'R134a cylinder delivery', 42000, now()->subDays(6)->toDateString()],
        ];
        foreach ($rows as [$category, $description, $amount, $date]) {
            Expense::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $owner->tenant_id, 'description' => $description, 'expense_date' => $date],
                [
                    'tenant_id' => $owner->tenant_id,
                    'category' => $category,
                    'description' => $description,
                    'amount' => $amount,
                    'amount_paid' => $amount,
                    'expense_date' => $date,
                    'payment_status' => Expense::STATUS_PAID,
                    'settled_at' => $date,
                    'created_by' => $owner->id,
                ],
            );
        }
    }
}
