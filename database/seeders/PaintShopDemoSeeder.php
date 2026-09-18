<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCheque;
use App\Models\Feature;
use App\Models\LaborCategory;
use App\Models\LaborItem;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BillCalculator;
use App\Support\BranchContext;
use App\Support\BusinessTypes;
use App\Support\PaintStockDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PaintShopDemoSeeder extends Seeder
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
            $this->forgetGarageDemoLeftovers($tenant);

            if (Bill::query()->where('bill_number', 'like', '%PNT01')->exists()) {
                return;
            }

            $parts = $this->seedStock($tenant);
            $labor = $this->laborByName((int) $tenant->id);
            $customers = $this->seedCustomers();
            $vehicles = $this->seedVehicles($customers);
            $suppliers = $this->seedSuppliers();
            $this->seedBills($owner, $customers, $vehicles, $parts, $labor);
            $this->seedExpensesAndCheques($owner, $suppliers);
        } finally {
            Auth::logout();
            BranchContext::clear();
        }
    }

    private function ensureTenant(): Tenant
    {
        $tenant = Tenant::query()->where('owner_email', 'owner@paint.lk')->first();
        if ($tenant) {
            return $tenant;
        }

        if (Feature::query()->doesntExist()) {
            $this->call(FeatureSeeder::class);
        }

        $tenant = Tenant::query()->create([
            'business_name' => 'Mr Paint',
            'business_type' => BusinessTypes::PAINT,
            'owner_name' => 'Paint Owner',
            'owner_phone' => '0773004005',
            'owner_phones' => [['label' => 'Primary', 'number' => '0773004005']],
            'owner_email' => 'owner@paint.lk',
            'contact_email' => 'owner@paint.lk',
            'contact_phone' => '0773004005',
            'contact_phones' => [['label' => 'Business', 'number' => '0773004005']],
            'address' => 'No. 48, Baseline Road, Colombo 09',
            'status' => 'active',
            'plan' => 'paint-pro',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ]);

        $keys = BusinessTypes::defaults(BusinessTypes::PAINT);
        $tenant->features()->sync(
            Feature::query()->whereIn('key', $keys)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['is_enabled' => true]])
                ->all()
        );

        $branch = Branch::ensureDefault($tenant);
        ServiceAddon::seedDefaultsFor((int) $tenant->id, BusinessTypes::PAINT);
        LaborCategory::seedDefaultsFor((int) $tenant->id, BusinessTypes::PAINT);
        PaintStockDefaults::seedFor((int) $tenant->id);

        User::query()->updateOrCreate(['email' => 'owner@paint.lk'], [
            'tenant_id' => $tenant->id,
            'name' => 'Paint Owner',
            'password' => Hash::make('password'),
            'role' => 'business_owner',
            'status' => 'active',
            'home_branch_id' => $branch->id,
            'last_branch_id' => $branch->id,
        ]);

        return $tenant;
    }

    private function forgetGarageDemoLeftovers(Tenant $tenant): void
    {
        $tenantId = (int) $tenant->id;
        $garageBills = Bill::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('bill_number', 'like', sprintf('INV-%03d-%%', $tenantId))
            ->get();
        foreach ($garageBills as $bill) {
            $bill->payments()->delete();
            $bill->items()->delete();
            $bill->delete();
        }

        Part::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sku', 'like', sprintf('T%03d-P%%', $tenantId))
            ->delete();

        Expense::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('description', ['Electricity bill', 'Workshop consumables'])
            ->delete();

        Vehicle::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('chassis_number', 'like', sprintf('CHS%03dA%%', $tenantId))
            ->whereDoesntHave('bills')
            ->delete();
    }

    /**
     * @return array<string, Part>
     */
    private function seedStock(Tenant $tenant): array
    {
        $catalog = [
            ['name' => '2K primer grey', 'brand' => 'Shop stock', 'type' => 'Primer', 'price' => 22, 'cost_price' => 12, 'stock_qty' => 12000, 'description' => 'Stocked in millilitres'],
            ['name' => 'HS clear coat', 'brand' => 'Shop stock', 'type' => 'Clear', 'price' => 38, 'cost_price' => 20, 'stock_qty' => 15000, 'description' => 'Stocked in millilitres'],
            ['name' => 'Thinner 2K', 'brand' => 'Shop stock', 'type' => 'Thinner', 'price' => 8, 'cost_price' => 4, 'stock_qty' => 20000, 'description' => 'Stocked in millilitres'],
            ['name' => '2K hardener', 'brand' => 'Shop stock', 'type' => 'Hardener', 'price' => 18, 'cost_price' => 10, 'stock_qty' => 8000, 'description' => 'Stocked in millilitres'],
            ['name' => 'White 2K base', 'brand' => 'Nippon', 'type' => 'Base', 'price' => 28, 'cost_price' => 16, 'stock_qty' => 10000, 'description' => 'Solid white tinter, millilitres'],
            ['name' => 'Black 2K base', 'brand' => 'Nippon', 'type' => 'Base', 'price' => 26, 'cost_price' => 15, 'stock_qty' => 9000, 'description' => 'Solid black tinter, millilitres'],
            ['name' => 'Silver metallic', 'brand' => 'Sikkens', 'type' => 'Base', 'price' => 42, 'cost_price' => 24, 'stock_qty' => 6000, 'description' => 'Metallic silver, millilitres'],
            ['name' => 'Candy red', 'brand' => 'Sikkens', 'type' => 'Base', 'price' => 48, 'cost_price' => 28, 'stock_qty' => 4500, 'description' => 'Candy red tinter, millilitres'],
            ['name' => 'Body filler', 'brand' => '3M', 'type' => 'Filler', 'price' => 6, 'cost_price' => 3, 'stock_qty' => 8000, 'description' => 'Polyester putty, millilitres'],
            ['name' => 'Plastic bumper primer', 'brand' => 'Shop stock', 'type' => 'Primer', 'price' => 20, 'cost_price' => 11, 'stock_qty' => 4000, 'description' => 'Adhesion primer, millilitres'],
            ['name' => 'Masking tape 24mm', 'brand' => '3M', 'type' => 'Consumable', 'price' => 180, 'cost_price' => 90, 'stock_qty' => 80, 'description' => 'Rolls'],
            ['name' => 'P80 sandpaper', 'brand' => 'Mirka', 'type' => 'Consumable', 'price' => 45, 'cost_price' => 18, 'stock_qty' => 200, 'description' => 'Sheets'],
        ];

        $parts = [];
        foreach ($catalog as $row) {
            $part = Part::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $row['name']],
                ['tenant_id' => $tenant->id, ...$row],
            );
            $parts[$row['name']] = $part->fresh();
        }

        return $parts;
    }

    /**
     * @return array<string, LaborItem>
     */
    private function laborByName(int $tenantId): array
    {
        return LaborItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('name')
            ->all();
    }

    /**
     * @return array<string, Customer>
     */
    private function seedCustomers(): array
    {
        $people = [
            ['Nimal Perera', '0771112233', 'Nugegoda'],
            ['Amara Silva', '0772223344', 'Maharagama'],
            ['Kasun Fernando', '0713334455', 'Kottawa'],
            ['Ishara Jayasuriya', '0764445566', 'Colombo 05'],
            ['Sanduni Fernando', '0755556677', 'Dehiwala'],
            ['Ruwan Jayawardena', '0726667788', 'Battaramulla'],
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
    private function seedVehicles(array $customers): array
    {
        $fleet = [
            ['0771112233', 'CAA-4521', 'PNTCHS001', 'Toyota', 'Aqua', 2018],
            ['0772223344', 'CAB-8832', 'PNTCHS002', 'Honda', 'Vezel', 2019],
            ['0713334455', 'CAC-1109', 'PNTCHS003', 'Suzuki', 'WagonR', 2020],
            ['0764445566', 'CAD-7744', 'PNTCHS004', 'Nissan', 'Leaf', 2021],
            ['0755556677', 'CAE-2210', 'PNTCHS005', 'Toyota', 'Premio', 2017],
            ['0726667788', 'CAF-9901', 'PNTCHS006', 'BMW', '320i', 2016],
        ];
        $vehicles = [];
        foreach ($fleet as [$phone, $plate, $chassis, $make, $model, $year]) {
            $vehicles[$plate] = Vehicle::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => Auth::user()->tenant_id, 'chassis_number' => $chassis],
                [
                    'tenant_id' => Auth::user()->tenant_id,
                    'customer_id' => $customers[$phone]->id,
                    'number_plate' => $plate,
                    'chassis_number' => $chassis,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                ],
            );
        }

        return $vehicles;
    }

    /**
     * @return array<string, Supplier>
     */
    private function seedSuppliers(): array
    {
        $houses = [
            ['Nippon Paint Lanka', '0112550100', 'Paint & primer drums'],
            ['Sikkens Colombo', '0112780450', 'Clear coat and metallics'],
        ];
        $suppliers = [];
        foreach ($houses as [$name, $phone, $notes]) {
            $suppliers[$name] = Supplier::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => Auth::user()->tenant_id, 'name' => $name],
                [
                    'tenant_id' => Auth::user()->tenant_id,
                    'phone' => $phone,
                    'notes' => $notes,
                    'active' => true,
                ],
            );
        }

        return $suppliers;
    }

    /**
     * @param  array<string, Customer>  $customers
     * @param  array<string, Vehicle>  $vehicles
     * @param  array<string, Part>  $parts
     * @param  array<string, LaborItem>  $labor
     */
    private function seedBills(User $owner, array $customers, array $vehicles, array $parts, array $labor): void
    {
        $calculator = app(BillCalculator::class);
        $jobs = [
            [
                'suffix' => 'PNT01', 'date' => now()->subDays(16)->toDateString(),
                'customer' => $customers['0771112233'], 'vehicle' => $vehicles['CAA-4521'],
                'notes' => 'Front bumper respray — Aqua white',
                'panels' => [[
                    'name' => 'Front bumper',
                    'labor' => [['Masking', 1.0], ['Primer spray', 1.0], ['Base coat', 1.5], ['Clear coat', 1.5]],
                    'materials' => [['Plastic bumper primer', 180], ['White 2K base', 220], ['HS clear coat', 200], ['2K hardener', 80]],
                ]],
                'pay' => 'full', 'method' => 'cash', 'close' => true,
            ],
            [
                'suffix' => 'PNT02', 'date' => now()->subDays(14)->toDateString(),
                'customer' => $customers['0772223344'], 'vehicle' => $vehicles['CAB-8832'],
                'notes' => 'Driver door blend — Vezel silver',
                'panels' => [[
                    'name' => 'Driver door',
                    'labor' => [['Wash & degrease', 0.5], ['Masking', 1.0], ['Color blend into adjacent panel', 2.5], ['Clear coat', 1.5], ['Polish', 1.0]],
                    'materials' => [['Silver metallic', 260], ['HS clear coat', 220], ['Thinner 2K', 180], ['2K hardener', 90]],
                ]],
                'pay' => 'full', 'method' => 'card', 'close' => true,
            ],
            [
                'suffix' => 'PNT03', 'date' => now()->subDays(12)->toDateString(),
                'customer' => $customers['0713334455'], 'vehicle' => $vehicles['CAC-1109'],
                'notes' => 'Scratch repair — WagonR rear quarter',
                'panels' => [[
                    'name' => 'Rear quarter',
                    'labor' => [['Body filler', 2.0], ['Guide coat sand', 1.0], ['Spot repair', 1.0], ['Clear coat', 1.0]],
                    'materials' => [['Body filler', 400], ['2K primer grey', 150], ['Black 2K base', 120], ['HS clear coat', 140]],
                ]],
                'pay' => 'full', 'method' => 'cash', 'close' => true,
            ],
            [
                'suffix' => 'PNT04', 'date' => now()->subDays(10)->toDateString(),
                'customer' => $customers['0764445566'], 'vehicle' => $vehicles['CAD-7744'],
                'notes' => 'Rear bumper — Leaf white',
                'panels' => [[
                    'name' => 'Rear bumper',
                    'labor' => [['Masking', 1.0], ['Primer spray', 1.0], ['Base coat', 1.5], ['Clear coat', 1.5], ['Quality check', 0.5]],
                    'materials' => [['Plastic bumper primer', 160], ['White 2K base', 200], ['HS clear coat', 180], ['Masking tape 24mm', 2]],
                ]],
                'pay' => 'full', 'method' => 'bank_transfer', 'close' => false,
            ],
            [
                'suffix' => 'PNT05', 'date' => now()->subDays(9)->toDateString(),
                'customer' => $customers['0755556677'], 'vehicle' => $vehicles['CAE-2210'],
                'notes' => 'Alloy refurb — Premio 16"',
                'panels' => [[
                    'name' => 'Alloys',
                    'labor' => [['Panel strip', 1.5], ['Primer spray', 1.0], ['Base coat', 1.5], ['Clear coat', 1.0]],
                    'materials' => [['2K primer grey', 120], ['Silver metallic', 180], ['HS clear coat', 150]],
                ]],
                'pay' => 'full', 'method' => 'card', 'close' => false,
            ],
            [
                'suffix' => 'PNT06', 'date' => now()->subDays(8)->toDateString(),
                'customer' => $customers['0726667788'], 'vehicle' => $vehicles['CAF-9901'],
                'notes' => 'Bonnet + bumper — BMW candy red',
                'panels' => [
                    [
                        'name' => 'Bonnet',
                        'labor' => [['Wash & degrease', 0.5], ['Masking', 1.0], ['Metallic / pearl', 2.0], ['Clear coat', 1.5]],
                        'materials' => [['Candy red', 320], ['HS clear coat', 240], ['2K hardener', 100], ['Thinner 2K', 200]],
                    ],
                    [
                        'name' => 'Front bumper',
                        'labor' => [['Masking', 1.0], ['Base coat', 1.5], ['Clear coat', 1.5]],
                        'materials' => [['Plastic bumper primer', 140], ['Candy red', 180], ['HS clear coat', 160]],
                    ],
                ],
                'pay' => 'full', 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'PNT07', 'date' => now()->subDays(7)->toDateString(),
                'customer' => $customers['0771112233'], 'vehicle' => $vehicles['CAA-4521'],
                'notes' => 'Bonnet respray — Aqua',
                'panels' => [[
                    'name' => 'Bonnet',
                    'labor' => [['Masking', 1.0], ['Primer spray', 1.0], ['Base coat', 1.5], ['Clear coat', 1.5], ['Polish', 1.0]],
                    'materials' => [['2K primer grey', 180], ['White 2K base', 240], ['HS clear coat', 200], ['P80 sandpaper', 6]],
                ]],
                'pay' => 0.4, 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'PNT08', 'date' => now()->subDays(6)->toDateString(),
                'customer' => $customers['0772223344'], 'vehicle' => $vehicles['CAB-8832'],
                'notes' => 'Wing blend — Vezel',
                'panels' => [[
                    'name' => 'Front left wing',
                    'labor' => [['Rust treatment', 1.0], ['Body filler', 2.0], ['Color blend into adjacent panel', 2.5], ['Clear coat', 1.5]],
                    'materials' => [['Body filler', 350], ['2K primer grey', 160], ['Silver metallic', 200], ['HS clear coat', 180]],
                ]],
                'pay' => 0.5, 'method' => 'card', 'close' => false,
            ],
            [
                'suffix' => 'PNT09', 'date' => now()->subDays(5)->toDateString(),
                'customer' => $customers['0713334455'], 'vehicle' => $vehicles['CAC-1109'],
                'notes' => 'Interior dash spray',
                'panels' => [[
                    'name' => 'Dashboard',
                    'labor' => [['Masking', 1.5], ['Spot repair', 1.0], ['Base coat', 1.0]],
                    'materials' => [['Black 2K base', 140], ['Thinner 2K', 80], ['Masking tape 24mm', 3]],
                ]],
                'pay' => 0.35, 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'PNT10', 'date' => now()->subDays(4)->toDateString(),
                'customer' => $customers['0764445566'], 'vehicle' => $vehicles['CAD-7744'],
                'notes' => 'Roof respray — in booth',
                'panels' => [[
                    'name' => 'Roof',
                    'labor' => [['Wash & degrease', 0.5], ['Masking', 1.5], ['Primer spray', 1.0], ['Base coat', 2.0], ['Clear coat', 1.5]],
                    'materials' => [['2K primer grey', 220], ['White 2K base', 300], ['HS clear coat', 260], ['2K hardener', 110]],
                ]],
                'pay' => 0, 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'PNT11', 'date' => now()->subDays(3)->toDateString(),
                'customer' => $customers['0755556677'], 'vehicle' => $vehicles['CAE-2210'],
                'notes' => 'Wing mirror housings',
                'panels' => [[
                    'name' => 'Mirrors',
                    'labor' => [['Masking', 0.5], ['Primer spray', 0.5], ['Base coat', 0.75], ['Clear coat', 0.75]],
                    'materials' => [['Plastic bumper primer', 60], ['Silver metallic', 80], ['HS clear coat', 70]],
                ]],
                'pay' => 0, 'method' => 'cash', 'close' => false,
            ],
            [
                'suffix' => 'PNT12', 'date' => now()->subDays(2)->toDateString(),
                'customer' => $customers['0726667788'], 'vehicle' => $vehicles['CAF-9901'],
                'notes' => 'Insurance — front bumper candy red',
                'panels' => [[
                    'name' => 'Front bumper',
                    'labor' => [['Panel strip', 1.5], ['Masking', 1.0], ['Metallic / pearl', 2.0], ['Clear coat', 1.5], ['Reassemble trim', 1.0]],
                    'materials' => [['Plastic bumper primer', 200], ['Candy red', 280], ['HS clear coat', 220], ['2K hardener', 90]],
                ]],
                'pay' => 0, 'method' => 'cash', 'close' => false, 'owe_in' => now()->toDateString(),
            ],
            [
                'suffix' => 'PNT13', 'date' => now()->subDays(1)->toDateString(),
                'customer' => $customers['0771112233'], 'vehicle' => $vehicles['CAA-4521'],
                'notes' => 'Full side — Aqua white, pay later',
                'panels' => [
                    [
                        'name' => 'Driver door',
                        'labor' => [['Masking', 1.0], ['Base coat', 1.5], ['Clear coat', 1.5]],
                        'materials' => [['White 2K base', 200], ['HS clear coat', 180], ['Thinner 2K', 120]],
                    ],
                    [
                        'name' => 'Rear door',
                        'labor' => [['Masking', 1.0], ['Base coat', 1.5], ['Clear coat', 1.5], ['Polish', 1.0]],
                        'materials' => [['White 2K base', 180], ['HS clear coat', 160]],
                    ],
                ],
                'pay' => 0, 'method' => 'cash', 'close' => false, 'owe_in' => now()->addDays(10)->toDateString(),
            ],
            [
                'suffix' => 'PNT14', 'date' => now()->toDateString(),
                'customer' => $customers['0772223344'], 'vehicle' => $vehicles['CAB-8832'],
                'notes' => 'Door + fender — deposit taken',
                'panels' => [[
                    'name' => 'Passenger door',
                    'labor' => [['Wash & degrease', 0.5], ['Body filler', 2.0], ['Color blend into adjacent panel', 2.5], ['Clear coat', 1.5], ['Wet sand', 1.5], ['Compound', 1.0]],
                    'materials' => [['Body filler', 280], ['2K primer grey', 160], ['Silver metallic', 220], ['HS clear coat', 200], ['P80 sandpaper', 8]],
                ]],
                'pay' => 0.3, 'method' => 'cash', 'close' => false, 'owe_in' => now()->addDays(5)->toDateString(),
            ],
        ];

        foreach ($jobs as $job) {
            $this->paintJob($owner, $calculator, $parts, $labor, $job);
        }
    }

    /**
     * @param  array<string, Part>  $parts
     * @param  array<string, LaborItem>  $labor
     * @param  array<string, mixed>  $job
     */
    private function paintJob(User $owner, BillCalculator $calculator, array $parts, array $labor, array $job): void
    {
        $date = $job['date'];
        $bill = Bill::query()->create([
            'tenant_id' => $owner->tenant_id,
            'branch_id' => BranchContext::id() ?: Branch::defaultIdFor($owner->tenant_id),
            'bill_number' => 'JOB-'.now()->parse($date)->format('Ymd').'-'.$job['suffix'],
            'customer_id' => $job['customer']->id,
            'vehicle_id' => $job['vehicle']->id,
            'admission_date' => $date,
            'odometer' => 45000 + ((int) substr($job['suffix'], -2) * 1370),
            'notes' => $job['notes'],
            'job_kind' => Bill::JOB_KIND_REPAIR,
            'created_by' => $owner->id,
        ]);

        foreach ($job['panels'] as $panel) {
            $groupId = (string) Str::uuid();
            foreach ($panel['labor'] as [$name, $hours]) {
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
                    'panel_group_id' => $groupId,
                    'panel_name' => $panel['name'],
                    'description' => $item->name,
                    'quantity' => $hours,
                    'unit_price' => $unit,
                    'line_total' => round($unit * $hours, 2),
                ]);
            }
            foreach ($panel['materials'] as [$name, $qty]) {
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
                    'panel_group_id' => $groupId,
                    'panel_name' => $panel['name'],
                    'description' => $part->name,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => round($unit * $qty, 2),
                    'purchase_unit_cost' => $part->cost_price,
                ]);
            }
        }

        $bill = $calculator->recalculate($bill);
        $pay = $job['pay'];
        $amount = $pay === 'full'
            ? (float) $bill->subtotal
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
                'paid_at' => now()->parse($date)->setTime(15, 30),
                'received_by' => $owner->id,
            ]);
            $bill = $calculator->recalculate($bill->fresh());
        }

        if (! empty($job['close']) && (float) $bill->balance_due <= 0) {
            $bill->update([
                'status' => 'closed',
                'closed_at' => now()->parse($date)->setTime(17, 0),
                'updated_by' => $owner->id,
            ]);
        }
    }

    /**
     * @param  array<string, Supplier>  $suppliers
     */
    private function seedExpensesAndCheques(User $owner, array $suppliers): void
    {
        $paid = [
            ['rent', 'Booth rent — September', 85000, now()->startOfMonth()->addDays(1)->toDateString()],
            ['utilities', 'Electricity — spray booth', 24600, now()->startOfMonth()->addDays(4)->toDateString()],
            ['utilities', 'Water bill', 4200, now()->startOfMonth()->addDays(6)->toDateString()],
            ['maintenance', 'Compressor service', 18500, now()->startOfMonth()->addDays(8)->toDateString()],
            ['misc', 'Booth filter pack', 9600, now()->subDays(5)->toDateString()],
        ];
        foreach ($paid as [$category, $description, $amount, $date]) {
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

        $primerBuy = Expense::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $owner->tenant_id, 'description' => 'Primer drums on credit — Nippon'],
            [
                'tenant_id' => $owner->tenant_id,
                'category' => 'inventory',
                'description' => 'Primer drums on credit — Nippon',
                'amount' => 125000,
                'amount_paid' => 0,
                'expense_date' => now()->subDays(8)->toDateString(),
                'payment_status' => Expense::STATUS_CREDIT,
                'due_date' => now()->addDays(7)->toDateString(),
                'settled_at' => null,
                'supplier_id' => $suppliers['Nippon Paint Lanka']->id,
                'created_by' => $owner->id,
            ],
        );
        $clearBuy = Expense::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $owner->tenant_id, 'description' => 'Clear coat drums on credit — Sikkens'],
            [
                'tenant_id' => $owner->tenant_id,
                'category' => 'inventory',
                'description' => 'Clear coat drums on credit — Sikkens',
                'amount' => 98000,
                'amount_paid' => 0,
                'expense_date' => now()->subDays(4)->toDateString(),
                'payment_status' => Expense::STATUS_CREDIT,
                'due_date' => now()->addDays(14)->toDateString(),
                'settled_at' => null,
                'supplier_id' => $suppliers['Sikkens Colombo']->id,
                'created_by' => $owner->id,
            ],
        );
        $thinnerBuy = Expense::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $owner->tenant_id, 'description' => 'Thinner drums on credit — Nippon'],
            [
                'tenant_id' => $owner->tenant_id,
                'category' => 'inventory',
                'description' => 'Thinner drums on credit — Nippon',
                'amount' => 42000,
                'amount_paid' => 0,
                'expense_date' => now()->subDays(2)->toDateString(),
                'payment_status' => Expense::STATUS_CREDIT,
                'due_date' => now()->addDays(21)->toDateString(),
                'settled_at' => null,
                'supplier_id' => $suppliers['Nippon Paint Lanka']->id,
                'created_by' => $owner->id,
            ],
        );

        $this->issueCheque($primerBuy, $owner, 'CHQ-PNT-1001', 75000, now()->subDay()->toDateString());
        $this->issueCheque($clearBuy, $owner, 'CHQ-PNT-1002', 50000, now()->toDateString());
        $this->issueCheque($thinnerBuy, $owner, 'CHQ-PNT-1003', 20000, now()->addDays(8)->toDateString());
    }

    private function issueCheque(Expense $expense, User $owner, string $number, float $amount, string $date): void
    {
        ExpenseCheque::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $owner->tenant_id, 'cheque_number' => $number],
            [
                'tenant_id' => $owner->tenant_id,
                'expense_id' => $expense->id,
                'amount' => $amount,
                'cheque_number' => $number,
                'cheque_date' => $date,
                'status' => ExpenseCheque::STATUS_PENDING,
                'created_by' => $owner->id,
            ],
        );
    }
}
