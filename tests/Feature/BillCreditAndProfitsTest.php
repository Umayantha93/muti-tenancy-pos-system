<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillCreditAndProfitsTest extends TestCase
{

    public function test_inventory_part_on_a_bill_deducts_stock_and_snapshots_cost(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $part = Part::create([
            'name' => 'Oil Filter', 'brand' => 'Bosch', 'type' => 'filter',
            'price' => 1500, 'cost_price' => 800, 'stock_qty' => 10,
        ]);

        $billId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('item.purchase_unit_cost', '800.00')
            ->assertJsonPath('item.unit_price', '1500.00');

        $this->assertSame(8, $part->fresh()->stock_qty);
    }

    public function test_owe_in_locks_edits_accepts_payment_and_auto_closes_when_paid(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $billId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor', 'description' => 'Service', 'quantity' => 1, 'unit_price' => 5000,
        ])->assertCreated();

        $this->postJson("/api/bills/{$billId}/close")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only paid bills can be closed.');

        $due = now()->addDays(3)->toDateString();
        $this->postJson("/api/bills/{$billId}/owe-in", ['due_date' => $due])
            ->assertOk()
            ->assertJsonPath('status', 'owe_in')
            ->assertJsonPath('owe_in_due_date', $due);

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor', 'description' => 'Extra', 'quantity' => 1, 'unit_price' => 500,
        ])->assertStatus(422);

        $this->postJson("/api/bills/{$billId}/payments", [
            'amount' => 2000, 'method' => 'cash',
        ])->assertCreated()->assertJsonPath('bill.status', 'owe_in');

        $this->postJson("/api/bills/{$billId}/payments", [
            'amount' => 3000, 'method' => 'cash',
        ])->assertCreated()->assertJsonPath('bill.status', 'closed');

        $bill = Bill::findOrFail($billId);
        $this->assertSame('closed', $bill->status);
        $this->assertNotNull($bill->closed_at);
        $this->assertSame($due, $bill->owe_in_due_date?->toDateString());
    }

    public function test_job_cards_list_puts_urgent_owe_in_first_then_open_then_closed(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);

        $closedId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$closedId}/items", [
            'type' => 'labor', 'description' => 'Done', 'quantity' => 1, 'unit_price' => 1000,
        ]);
        $this->postJson("/api/bills/{$closedId}/payments", ['amount' => 1000, 'method' => 'cash']);
        $this->postJson("/api/bills/{$closedId}/close")->assertOk();

        $openId = $this->openJob()->json('id');

        $partialId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$partialId}/items", [
            'type' => 'labor', 'description' => 'Partial', 'quantity' => 1, 'unit_price' => 2000,
        ]);
        $this->postJson("/api/bills/{$partialId}/payments", ['amount' => 500, 'method' => 'cash']);

        $oweId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$oweId}/items", [
            'type' => 'labor', 'description' => 'Credit', 'quantity' => 1, 'unit_price' => 3000,
        ]);
        $this->postJson("/api/bills/{$oweId}/owe-in", [
            'due_date' => now()->addDays(2)->toDateString(),
        ])->assertOk();

        $ids = collect($this->getJson('/api/bills?per_page=50')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$oweId, $openId, $partialId, $closedId], $ids);
    }

    public function test_credit_inventory_purchase_does_not_hit_profit_until_settled(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $part = Part::create([
            'name' => 'Brake Pads', 'brand' => 'Nissin', 'type' => 'brake',
            'price' => 7800, 'cost_price' => 6200, 'stock_qty' => 2,
        ]);

        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 50,
            'unit_cost' => 6200,
            'payment_status' => 'credit',
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertOk();

        $this->assertSame(52, $part->fresh()->stock_qty);
        $this->assertSame(1, Expense::credit()->count());

        $sheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)
            ->assertOk()
            ->json();

        $this->assertEquals(0, $sheet['expenses']);
        $this->assertEquals(310000, $sheet['inventory_payables']['payables_total']);
        $this->assertTrue(collect($sheet['accounts'])->contains(fn ($row) => $row['type'] === 'payable'));

        $expenseId = Expense::credit()->first()->id;
        $this->postJson("/api/expenses/{$expenseId}/settle")->assertOk();

        $settled = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)
            ->assertOk()
            ->json();
        $this->assertEquals(310000, $settled['expenses']);
        $this->assertEquals(0, $settled['inventory_payables']['payables_total']);
    }

    public function test_bill_profits_report_includes_revenue_cogs_and_credit_bills(): void
    {
        $user = $this->garageUser(['bill_profits']);
        Sanctum::actingAs($user);
        $part = Part::create([
            'name' => 'Filter', 'brand' => 'Bosch', 'type' => 'filter',
            'price' => 2000, 'cost_price' => 800, 'stock_qty' => 10,
        ]);

        $billId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 1,
        ]);
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor', 'description' => 'Fit', 'quantity' => 1, 'unit_price' => 1000,
        ]);
        $this->postJson("/api/bills/{$billId}/owe-in", [
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $report = $this->getJson('/api/bill-profits')->assertOk()->json();
        $this->assertEquals(3000, $report['total_revenue']);
        $this->assertEquals(800, $report['total_cogs']);
        $this->assertEquals(2200, $report['gross_profit']);
        $this->assertEquals(1, $report['credit_count']);
        $this->assertEquals(3000, $report['credit_generated']);
        $this->assertEquals(3000, $report['credit_pending']);

        $detail = $this->getJson("/api/bill-profits/{$billId}")->assertOk()->json();
        $this->assertSame('credit', $detail['billing_type']);
        $this->assertSame('credit', $detail['payment_status']);
        $this->assertEquals(800, $detail['cogs']);
        $this->assertEquals(2200, $detail['profit']);
    }

    public function test_staff_without_bill_profits_cannot_view_report(): void
    {
        $staff = $this->garageUser();
        $feature = Feature::where('key', 'bill_profits')->firstOrFail();
        $staff->permissions()->syncWithoutDetaching([$feature->id => ['can_access' => false]]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/bill-profits')->assertForbidden();
    }

    /**
     * @param  list<string>  $extraFeatures
     */
    private function garageUser(array $extraFeatures = []): User
    {
        $keys = array_values(array_unique(array_merge([
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports', 'bill_profits',
        ], $extraFeatures)));

        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(), 'business_type' => 'garage', 'owner_name' => fake()->name(),
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'staff', 'status' => 'active']);
        $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));

        return $user;
    }

    private function openJob()
    {
        return $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
        ])->assertCreated();
    }
}
