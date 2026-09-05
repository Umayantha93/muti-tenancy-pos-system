<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillCalculator;
use App\Support\BranchContext;
use App\Support\WarrantyPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class MyDearShopDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('business_name', 'MyDearShop')->first();
        if (! $tenant) {
            return;
        }

        $owner = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'business_owner')
            ->first();
        if (! $owner) {
            return;
        }

        Auth::login($owner);
        BranchContext::set((int) Branch::defaultIdFor($tenant->id));

        $parts = $this->seedCatalog($tenant);
        $customers = $this->seedCustomers();

        if (Bill::query()->where('bill_number', 'like', '%A15PHN')->exists()) {
            Auth::logout();
            BranchContext::clear();

            return;
        }

        $calculator = app(BillCalculator::class);
        $nimal = $customers['0771234567'];
        $kamal = $customers['0775556677'];
        $sanduni = $customers['0718899001'];
        $ruwan = $customers['0763344556'];
        $walkin = $customers['0000000000'];

        $this->saleBill('A15PHN', '2026-08-28', $nimal, $owner, [
            ['part' => $parts['PHN-A15'], 'qty' => 1, 'months' => 12],
            ['part' => $parts['GLS-A15'], 'qty' => 1],
            ['part' => $parts['CVR-A15'], 'qty' => 1],
        ], 60090, 'cash', 'A15 phone + glass + cover', $calculator);

        $this->saleBill('N13PHN', '2026-08-30', $sanduni, $owner, [
            ['part' => $parts['PHN-N13'], 'qty' => 1, 'months' => 12],
            ['part' => $parts['CHG-25W'], 'qty' => 1, 'months' => 12],
        ], 54940, 'card', 'Note 13 with charger', $calculator);

        $this->saleBill('EAR01', '2026-09-01', $kamal, $owner, [
            ['part' => $parts['EAR-TWS'], 'qty' => 1, 'months' => 12],
        ], 3950, 'cash', null, $calculator);

        $this->saleBill('PB01', '2026-09-02', $ruwan, $owner, [
            ['part' => $parts['PB-10K'], 'qty' => 1, 'months' => 12],
            ['part' => $parts['CBL-C-1'], 'qty' => 2],
        ], 0, 'cash', 'Pay later — power bank', $calculator);

        $this->saleBill('SD01', '2026-09-03', $nimal, $owner, [
            ['part' => $parts['SD-128'], 'qty' => 1, 'months' => 24],
        ], 2000, 'cash', 'Partial on memory card', $calculator);

        $this->quickBill('DVD01', '2026-08-29', $walkin, $owner, 'DVD write — wedding video', 350, 2, 700, 'cash', $calculator);
        $this->quickBill('CD01', '2026-09-01', $kamal, $owner, 'CD copy — school project', 150, 4, 600, 'cash', $calculator);
        $this->quickBill('DVD02', '2026-09-03', $sanduni, $owner, 'DVD write — exam papers PDF', 250, 1, 250, 'cash', $calculator);
        $this->quickBill('DVD03', '2026-09-04', $walkin, $owner, 'Movie DVD write', 200, 3, 0, 'cash', $calculator);
        $this->quickBill('DATA1', '2026-09-05', $ruwan, $owner, 'Data backup DVD', 300, 1, 300, 'cash', $calculator);

        $this->mixedBill('MIX01', '2026-09-04', $nimal, $owner, $parts['DVD-10'], 1, 'DVD write — family photos', 250, 1100, $calculator);
        $this->mixedBill('MIX02', '2026-09-05', $kamal, $owner, $parts['CVR-A15'], 1, 'CD copy — music album', 150, 600, $calculator);

        Auth::logout();
        BranchContext::clear();
    }

    /**
     * @return array<string, Part>
     */
    private function seedCatalog(Tenant $tenant): array
    {
        $catalog = [
            ['name' => 'Samsung Galaxy A15', 'sku' => 'PHN-A15', 'barcode' => '8901234567101', 'brand' => 'Samsung', 'type' => 'Phone', 'model' => 'Galaxy A15', 'year' => 2024, 'price' => 58990, 'cost_price' => 51200, 'stock_qty' => 6, 'description' => 'Local warranty phone'],
            ['name' => 'Redmi Note 13', 'sku' => 'PHN-N13', 'barcode' => '8901234567102', 'brand' => 'Xiaomi', 'type' => 'Phone', 'model' => 'Note 13', 'year' => 2024, 'price' => 52490, 'cost_price' => 45800, 'stock_qty' => 5, 'description' => 'Local warranty'],
            ['name' => 'USB-C charger 25W', 'sku' => 'CHG-25W', 'barcode' => '8901234567001', 'brand' => 'Baseus', 'type' => 'Charger', 'model' => 'Super Si', 'year' => 2025, 'price' => 2450, 'cost_price' => 1600, 'stock_qty' => 24, 'description' => 'Fast USB-C wall charger'],
            ['name' => 'Lightning cable 1m', 'sku' => 'CBL-LTG-1', 'barcode' => '8901234567002', 'brand' => 'Anker', 'type' => 'Cable', 'model' => 'PowerLine III', 'year' => 2024, 'price' => 1850, 'cost_price' => 980, 'stock_qty' => 40, 'description' => 'Braided Lightning cable'],
            ['name' => 'Tempered glass A15', 'sku' => 'GLS-A15', 'barcode' => '8901234567003', 'brand' => 'Nillkin', 'type' => 'Screen guard', 'model' => 'Galaxy A15', 'year' => 2024, 'price' => 650, 'cost_price' => 220, 'stock_qty' => 60, 'description' => '9H tempered glass'],
            ['name' => 'TWS earbuds', 'sku' => 'EAR-TWS', 'barcode' => '8901234567004', 'brand' => 'Oraimo', 'type' => 'Audio', 'model' => 'FreePods 4', 'year' => 2025, 'price' => 3950, 'cost_price' => 2400, 'stock_qty' => 12, 'description' => 'Wireless earbuds'],
            ['name' => 'Power bank 10000mAh', 'sku' => 'PB-10K', 'barcode' => '8901234567005', 'brand' => 'Xiaomi', 'type' => 'Power bank', 'model' => 'Redmi 10K', 'year' => 2024, 'price' => 4250, 'cost_price' => 2800, 'stock_qty' => 18, 'description' => '22.5W fast charge'],
            ['name' => 'Phone back cover A15', 'sku' => 'CVR-A15', 'barcode' => '8901234567006', 'brand' => 'Generic', 'type' => 'Cover', 'model' => 'Galaxy A15', 'year' => 2024, 'price' => 450, 'cost_price' => 180, 'stock_qty' => 35, 'description' => 'Premium silicone case'],
            ['name' => 'USB-C cable 1m', 'sku' => 'CBL-C-1', 'barcode' => '8901234567007', 'brand' => 'Baseus', 'type' => 'Cable', 'model' => 'Crystal Shine', 'year' => 2025, 'price' => 950, 'cost_price' => 420, 'stock_qty' => 50, 'description' => '60W USB-C cable'],
            ['name' => 'Memory card 128GB', 'sku' => 'SD-128', 'barcode' => '8901234567008', 'brand' => 'SanDisk', 'type' => 'Storage', 'model' => 'Ultra', 'year' => 2025, 'price' => 3450, 'cost_price' => 2100, 'stock_qty' => 20, 'description' => 'Class 10 microSD'],
            ['name' => 'Blank DVD-R 4.7GB (pack 10)', 'sku' => 'DVD-10', 'barcode' => '8901234567009', 'brand' => 'Sony', 'type' => 'Media', 'model' => 'DVD-R', 'year' => 2024, 'price' => 850, 'cost_price' => 480, 'stock_qty' => 30, 'description' => 'Blank discs for writing'],
            ['name' => 'Blank CD-R 700MB (pack 10)', 'sku' => 'CD-10', 'barcode' => '8901234567010', 'brand' => 'Sony', 'type' => 'Media', 'model' => 'CD-R', 'year' => 2024, 'price' => 550, 'cost_price' => 280, 'stock_qty' => 28, 'description' => 'Blank CDs for writing'],
        ];

        $parts = [];
        foreach ($catalog as $row) {
            $part = Part::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'barcode' => $row['barcode']],
                ['tenant_id' => $tenant->id, ...$row],
            );
            $parts[$row['sku']] = $part;
        }

        return $parts;
    }

    /**
     * @return array<string, Customer>
     */
    private function seedCustomers(): array
    {
        $people = [
            ['Nimal Perera', '0771234567'],
            ['Kamal Silva', '0775556677'],
            ['Sanduni Fernando', '0718899001'],
            ['Ruwan Jayasuriya', '0763344556'],
            ['Walk-in customer', '0000000000'],
        ];
        $customers = [];
        foreach ($people as [$name, $phone]) {
            $customers[$phone] = Customer::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => Auth::user()->tenant_id, 'phone' => $phone],
                ['tenant_id' => Auth::user()->tenant_id, 'name' => $name],
            );
        }

        return $customers;
    }

    /**
     * @param  list<array{part: Part, qty: int, months?: int}>  $lines
     */
    private function saleBill(
        string $suffix,
        string $date,
        Customer $customer,
        User $owner,
        array $lines,
        float $pay,
        string $method,
        ?string $notes,
        BillCalculator $calculator,
    ): Bill {
        $bill = Bill::query()->create([
            'tenant_id' => $owner->tenant_id,
            'branch_id' => BranchContext::id() ?: Branch::defaultIdFor($owner->tenant_id),
            'bill_number' => 'SALE-'.now()->parse($date)->format('Ymd').'-'.$suffix,
            'customer_id' => $customer->id,
            'admission_date' => $date,
            'notes' => $notes,
            'job_kind' => Bill::JOB_KIND_PARTS_SALE,
            'created_by' => $owner->id,
        ]);
        foreach ($lines as $line) {
            $part = $line['part'];
            $qty = $line['qty'];
            $part->takeStock($qty, $bill->branch_id);
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
                ...WarrantyPeriod::resolve(null, $line['months'] ?? null, null, $bill->admission_date),
            ]);
        }
        $calculator->recalculate($bill);
        $this->pay($bill, $pay, $method, $date, $owner, $calculator, 11);

        return $bill->fresh();
    }

    private function quickBill(
        string $suffix,
        string $date,
        Customer $customer,
        User $owner,
        string $job,
        float $amount,
        int $qty,
        float $pay,
        string $method,
        BillCalculator $calculator,
    ): Bill {
        $lineTotal = round($amount * $qty, 2);
        $bill = Bill::query()->create([
            'tenant_id' => $owner->tenant_id,
            'branch_id' => BranchContext::id() ?: Branch::defaultIdFor($owner->tenant_id),
            'bill_number' => 'QCK-'.now()->parse($date)->format('Ymd').'-'.$suffix,
            'customer_id' => $customer->id,
            'admission_date' => $date,
            'notes' => $job,
            'job_kind' => Bill::JOB_KIND_PARTS_SALE,
            'created_by' => $owner->id,
        ]);
        BillItem::query()->create([
            'tenant_id' => $owner->tenant_id,
            'bill_id' => $bill->id,
            'type' => 'charge',
            'description' => $job,
            'quantity' => $qty,
            'unit_price' => $amount,
            'line_total' => $lineTotal,
        ]);
        $calculator->recalculate($bill);
        $this->pay($bill, $pay, $method, $date, $owner, $calculator, 16);

        return $bill->fresh();
    }

    private function mixedBill(
        string $suffix,
        string $date,
        Customer $customer,
        User $owner,
        Part $part,
        int $qty,
        string $job,
        float $jobAmount,
        float $pay,
        BillCalculator $calculator,
    ): Bill {
        $bill = Bill::query()->create([
            'tenant_id' => $owner->tenant_id,
            'branch_id' => BranchContext::id() ?: Branch::defaultIdFor($owner->tenant_id),
            'bill_number' => 'SALE-'.now()->parse($date)->format('Ymd').'-'.$suffix,
            'customer_id' => $customer->id,
            'admission_date' => $date,
            'notes' => $job,
            'job_kind' => Bill::JOB_KIND_PARTS_SALE,
            'created_by' => $owner->id,
        ]);
        $part->takeStock($qty, $bill->branch_id);
        BillItem::query()->create([
            'tenant_id' => $owner->tenant_id,
            'bill_id' => $bill->id,
            'type' => 'part',
            'part_id' => $part->id,
            'description' => $part->name,
            'quantity' => $qty,
            'unit_price' => $part->price,
            'line_total' => round((float) $part->price * $qty, 2),
            'purchase_unit_cost' => $part->cost_price,
        ]);
        BillItem::query()->create([
            'tenant_id' => $owner->tenant_id,
            'bill_id' => $bill->id,
            'type' => 'charge',
            'description' => $job,
            'quantity' => 1,
            'unit_price' => $jobAmount,
            'line_total' => $jobAmount,
        ]);
        $calculator->recalculate($bill);
        $this->pay($bill, $pay, 'cash', $date, $owner, $calculator, 15);

        return $bill->fresh();
    }

    private function pay(Bill $bill, float $pay, string $method, string $date, User $owner, BillCalculator $calculator, int $hour): void
    {
        if ($pay <= 0) {
            return;
        }
        BillPayment::query()->create([
            'tenant_id' => $owner->tenant_id,
            'bill_id' => $bill->id,
            'amount' => $pay,
            'method' => $method,
            'paid_at' => now()->parse($date)->setTime($hour, 20),
            'received_by' => $owner->id,
        ]);
        $calculator->recalculate($bill->fresh());
    }
}
