<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\Part;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImplementationPlanTest extends TestCase
{

    public function test_garage_bill_without_vat_keeps_today_totals(): void
    {
        Sanctum::actingAs($this->garageUser());
        $part = Part::create([
            'name' => 'Brake Pad Set', 'brand' => 'Akebono', 'type' => 'brake',
            'model' => 'Corolla', 'year' => 2018, 'price' => 12500, 'cost_price' => 8000, 'stock_qty' => 10,
        ]);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1234',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 2,
        ])->assertCreated()->assertJsonPath('bill.subtotal', '25000.00');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'discount', 'description' => 'Loyalty discount', 'quantity' => 1, 'unit_price' => 1000,
        ])->assertCreated()
            ->assertJsonPath('bill.balance_due', '24000.00')
            ->assertJsonPath('bill.vat_amount', '0.00');
    }

    public function test_vat_registered_new_bills_tax_net_and_legacy_bills_stay_zero(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);

        $legacyId = $this->postJson('/api/bills', [
            'customer_name' => 'Old Job',
            'customer_phone' => '0771111111',
            'number_plate' => 'CAB-0001',
        ])->assertCreated()->json('id');

        $user->tenant->update(['vat_registered' => true, 'vat_rate' => 18, 'sscl_registered' => true, 'sscl_rate' => 2.5]);

        $this->postJson("/api/bills/{$legacyId}/items", [
            'type' => 'labor', 'description' => 'Work', 'quantity' => 1, 'unit_price' => 10000,
        ])->assertCreated()
            ->assertJsonPath('bill.subtotal', '10000.00')
            ->assertJsonPath('bill.vat_amount', '0.00')
            ->assertJsonPath('bill.balance_due', '10000.00');

        $newId = $this->postJson('/api/bills', [
            'customer_name' => 'New Job',
            'customer_phone' => '0772222222',
            'number_plate' => 'CAB-0002',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$newId}/items", [
            'type' => 'labor', 'description' => 'Work', 'quantity' => 1, 'unit_price' => 10000,
        ])->assertCreated()
            ->assertJsonPath('bill.subtotal', '10000.00')
            ->assertJsonPath('bill.vat_amount', '1800.00')
            ->assertJsonPath('bill.sscl_amount', '250.00')
            ->assertJsonPath('bill.balance_due', '12050.00');
    }

    public function test_payroll_without_epf_or_shift_uses_eight_hour_overtime(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $employee = Employee::create([
            'name' => 'Kasun',
            'nic' => '901234567V',
            'phone' => '0773334444',
            'position' => 'Technician',
            'base_salary' => 50000,
            'overtime_hourly_rate' => 100,
            'fingerprint_id' => 'FP-1',
            'active' => true,
        ]);

        $this->postJson('/api/attendance', [
            'employee_id' => $employee->id,
            'check_in' => '2026-08-03 08:00:00',
            'check_out' => '2026-08-03 17:00:00',
        ])->assertCreated()->assertJsonPath('overtime_hours', '1.00');

        $this->postJson('/api/payroll/generate', ['month' => 8, 'year' => 2026])
            ->assertOk()
            ->assertJsonPath('data.0.net_salary', '50100.00')
            ->assertJsonPath('data.0.epf_employee', '0.00');
    }

    public function test_epf_flag_applies_eight_twelve_three(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        Employee::create([
            'name' => 'EPF Staff',
            'nic' => '851234567V',
            'phone' => '0775556666',
            'position' => 'Clerk',
            'base_salary' => 50000,
            'overtime_hourly_rate' => 0,
            'fingerprint_id' => 'FP-2',
            'active' => true,
            'epf_enabled' => true,
        ]);

        $this->postJson('/api/payroll/generate', ['month' => 8, 'year' => 2026])
            ->assertOk()
            ->assertJsonPath('data.0.epf_employee', '4000.00')
            ->assertJsonPath('data.0.epf_employer', '6000.00')
            ->assertJsonPath('data.0.etf_employer', '1500.00')
            ->assertJsonPath('data.0.net_salary', '46000.00');
    }

    public function test_restock_blends_cost_price_as_weighted_average(): void
    {
        Sanctum::actingAs($this->garageUser());
        $part = Part::create([
            'name' => 'Air Filter', 'brand' => 'Denso', 'type' => 'filter',
            'price' => 2400, 'cost_price' => 2100, 'stock_qty' => 10,
        ]);

        // Sell nothing; buy another 10 @ 2200 while 10 remain → (10×2100 + 10×2200) / 20 = 2150
        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 10,
            'unit_cost' => 2200,
            'payment_status' => 'paid',
        ])
            ->assertOk()
            ->assertJsonPath('part.stock_qty', 20)
            ->assertJsonPath('part.cost_price', '2150.00')
            ->assertJsonPath('expense.amount', '22000.00');

        // 5 left @ 2150, then buy 10 @ 2200 → (5×2150 + 10×2200) / 15 = 2183.33
        $part->update(['stock_qty' => 5]);
        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 10,
            'unit_cost' => 2200,
            'payment_status' => 'paid',
        ])
            ->assertOk()
            ->assertJsonPath('part.stock_qty', 15)
            ->assertJsonPath('part.cost_price', '2183.33');
    }

    public function test_restock_without_supplier_still_records_expense(): void
    {
        Sanctum::actingAs($this->garageUser());
        $part = Part::create([
            'name' => 'Oil Filter', 'brand' => 'Toyota', 'type' => 'filter',
            'price' => 1500, 'cost_price' => 800, 'stock_qty' => 10,
        ]);

        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 5,
            'unit_cost' => 800,
            'payment_status' => 'paid',
        ])->assertOk();

        $this->assertSame(15, $part->fresh()->stock_qty);
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(0, StockReceipt::query()->count());
    }

    public function test_restock_with_supplier_creates_goods_received_note(): void
    {
        $user = $this->garageUser(['suppliers']);
        Sanctum::actingAs($user);
        $supplier = Supplier::create(['name' => 'Lanka Parts', 'phone' => '0112223333']);
        $part = Part::create([
            'name' => 'Tyre 185', 'brand' => 'CEAT', 'type' => 'tyre',
            'price' => 18000, 'cost_price' => 14000, 'stock_qty' => 4,
        ]);

        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 2,
            'unit_cost' => 14000,
            'payment_status' => 'paid',
            'supplier_id' => $supplier->id,
        ])->assertOk();

        $this->assertSame(1, StockReceipt::query()->count());
        $this->assertSame($supplier->id, StockReceipt::query()->first()->supplier_id);
    }

    public function test_reports_endpoint_returns_payload_when_feature_enabled(): void
    {
        Sanctum::actingAs($this->garageUser());

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonStructure(['from', 'to', 'sales', 'stock', 'receivables', 'staff']);
    }

    public function test_tyre_shop_still_opens_a_vehicle_job_card(): void
    {
        $user = $this->garageUser();
        $user->tenant->update(['business_type' => 'tyre']);
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/bills', [
            'customer_name' => 'Tyre Customer',
            'customer_phone' => '0777778888',
            'number_plate' => 'CAA-9999',
            'tyre_size' => '185/65R15',
            'axle' => 'front',
        ])->assertCreated()
            ->assertJsonPath('vehicle.number_plate', 'CAA-9999');
    }

    /**
     * @param  list<string>  $extraFeatures
     */
    private function garageUser(array $extraFeatures = []): User
    {
        $keys = [
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports',
            ...$extraFeatures,
        ];
        $features = collect($keys)->unique()->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(), 'business_type' => 'garage', 'owner_name' => fake()->name(),
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);
    }
}
