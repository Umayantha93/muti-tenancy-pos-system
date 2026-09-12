<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BranchContext;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillRefundTest extends TestCase
{
    public function test_refund_rejected_unless_bill_is_closed(): void
    {
        Sanctum::actingAs($this->garageUser());
        $billId = $this->paidOpenBill(1000);

        $itemId = BillItem::query()->where('bill_id', $billId)->value('id');

        $this->postJson("/api/bills/{$billId}/refunds", [
            'refunded_at' => now()->toDateString(),
            'reason' => 'Customer changed mind',
            'method' => 'cash',
            'items' => [
                ['bill_item_id' => $itemId, 'quantity' => 1, 'disposition' => 'none'],
            ],
        ])->assertStatus(422)->assertJsonPath('message', 'Only closed bills can be refunded.');
    }

    public function test_restock_refund_returns_stock_and_write_off_does_not(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $part = $this->stockedPart($user, [
            'name' => 'DVD Movie', 'brand' => 'Local', 'type' => 'media',
            'price' => 500, 'cost_price' => 200, 'stock_qty' => 5,
        ]);

        $billId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 2,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->postJson("/api/bills/{$billId}/close")->assertOk();
        $this->assertSame(3, $part->fresh()->stock_qty);

        $items = BillItem::query()->where('bill_id', $billId)->where('type', 'part')->get();
        $this->assertCount(1, $items);
        $itemId = $items->first()->id;

        $this->postJson("/api/bills/{$billId}/refunds", [
            'refunded_at' => now()->toDateString(),
            'reason' => 'Customer swapped for another title',
            'method' => 'cash',
            'items' => [
                ['bill_item_id' => $itemId, 'quantity' => 1, 'disposition' => 'restock'],
            ],
        ])->assertCreated()->assertJsonPath('amount', '500.00');

        $this->assertSame(4, $part->fresh()->stock_qty);
        $this->assertSame('500.00', Bill::findOrFail($billId)->amount_refunded);

        $this->postJson("/api/bills/{$billId}/refunds", [
            'refunded_at' => now()->toDateString(),
            'reason' => 'Disc would not play',
            'method' => 'cash',
            'items' => [
                ['bill_item_id' => $itemId, 'quantity' => 1, 'disposition' => 'write_off'],
            ],
        ])->assertCreated();

        $this->assertSame(4, $part->fresh()->stock_qty);
        $bill = Bill::findOrFail($billId);
        $this->assertSame('1000.00', $bill->amount_refunded);
        $this->assertSame('closed', $bill->status);
        $this->assertSame('refunded', $bill->refundStatus());
    }

    public function test_cannot_refund_more_than_remaining_quantity_or_cash(): void
    {
        Sanctum::actingAs($this->garageUser());
        $billId = $this->paidClosedBill(2000);
        $itemId = BillItem::query()->where('bill_id', $billId)->value('id');

        $this->postJson("/api/bills/{$billId}/refunds", [
            'refunded_at' => now()->toDateString(),
            'reason' => 'Too much',
            'method' => 'cash',
            'items' => [
                ['bill_item_id' => $itemId, 'quantity' => 2, 'disposition' => 'none'],
            ],
        ])->assertStatus(422);
    }

    public function test_finance_income_drops_on_refund_date(): void
    {
        Sanctum::actingAs($this->garageUser(['balance_sheet']));
        $billId = $this->paidClosedBill(3000);
        $itemId = BillItem::query()->where('bill_id', $billId)->value('id');

        $month = now()->month;
        $year = now()->year;
        $before = $this->getJson("/api/balance-sheet?month={$month}&year={$year}")->assertOk()->json('income');

        $this->postJson("/api/bills/{$billId}/refunds", [
            'refunded_at' => now()->toDateString(),
            'reason' => 'Partial cash back',
            'method' => 'cash',
            'items' => [
                ['bill_item_id' => $itemId, 'quantity' => 1, 'disposition' => 'none'],
            ],
        ])->assertCreated();

        $after = $this->getJson("/api/balance-sheet?month={$month}&year={$year}")->assertOk()->json();
        $this->assertEqualsWithDelta((float) $before - 3000, (float) $after['income'], 0.01);
        $this->assertTrue(collect($after['accounts'])->contains(
            fn (array $row) => ($row['type'] ?? null) === 'refund' && (float) $row['debit'] === 3000.0
        ));
    }

    public function test_bill_profits_restock_reverses_revenue_and_cogs_write_off_keeps_cogs(): void
    {
        $user = $this->garageUser(['bill_profits']);
        Sanctum::actingAs($user);
        $part = $this->stockedPart($user, [
            'name' => 'Oil Filter', 'brand' => 'Bosch', 'type' => 'filter',
            'price' => 1500, 'cost_price' => 800, 'stock_qty' => 10,
        ]);

        $billId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 2,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/payments", ['amount' => 3000, 'method' => 'cash'])->assertCreated();
        $this->postJson("/api/bills/{$billId}/close")->assertOk();

        $before = $this->getJson("/api/bill-profits/{$billId}")->assertOk()->json();
        $this->assertEqualsWithDelta(3000.0, (float) $before['revenue'], 0.01);
        $this->assertEqualsWithDelta(1600.0, (float) $before['cogs'], 0.01);

        $itemId = BillItem::query()->where('bill_id', $billId)->value('id');
        $this->postJson("/api/bills/{$billId}/refunds", [
            'refunded_at' => now()->toDateString(),
            'reason' => 'Return good item',
            'method' => 'cash',
            'items' => [
                ['bill_item_id' => $itemId, 'quantity' => 1, 'disposition' => 'restock'],
            ],
        ])->assertCreated();

        $restocked = $this->getJson("/api/bill-profits/{$billId}")->assertOk()->json();
        $this->assertEqualsWithDelta(1500.0, (float) $restocked['revenue'], 0.01);
        $this->assertEqualsWithDelta(800.0, (float) $restocked['cogs'], 0.01);
        $this->assertSame('partially_refunded', $restocked['refund_status']);

        $this->postJson("/api/bills/{$billId}/refunds", [
            'refunded_at' => now()->toDateString(),
            'reason' => 'Broken on install',
            'method' => 'cash',
            'items' => [
                ['bill_item_id' => $itemId, 'quantity' => 1, 'disposition' => 'write_off'],
            ],
        ])->assertCreated();

        $writtenOff = $this->getJson("/api/bill-profits/{$billId}")->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, (float) $writtenOff['revenue'], 0.01);
        $this->assertEqualsWithDelta(800.0, (float) $writtenOff['cogs'], 0.01);
        $this->assertEqualsWithDelta(-800.0, (float) $writtenOff['profit'], 0.01);
        $this->assertSame('refunded', $writtenOff['refund_status']);
    }

    public function test_closed_bill_rejects_notes_employees_and_video_upload(): void
    {
        Sanctum::actingAs($this->garageUser(['job_videos', 'employees_management']));
        $billId = $this->paidClosedBill(1000);
        $employee = Employee::create([
            'name' => 'Sewu', 'nic' => '199012345678', 'phone' => '0771112222',
            'position' => 'Mechanic', 'base_salary' => 50000, 'fingerprint_id' => 'fp-refund-1', 'active' => true,
        ]);

        $this->putJson("/api/bills/{$billId}", [
            'internal_notes' => 'Should not save',
        ])->assertStatus(422)->assertJsonPath('message', 'Closed bills cannot be edited.');

        $this->putJson("/api/bills/{$billId}/employees", [
            'employee_ids' => [$employee->id],
        ])->assertStatus(422)->assertJsonPath('message', 'Closed bills cannot be edited.');

        $this->postJson("/api/bills/{$billId}/videos", [])
            ->assertStatus(422);
    }

    /**
     * @param  list<string>  $extraFeatures
     */
    private function garageUser(array $extraFeatures = []): User
    {
        BranchContext::clear();

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
        $branchId = Branch::defaultIdFor($tenant->id);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'staff',
            'status' => 'active',
            'home_branch_id' => $branchId,
            'last_branch_id' => $branchId,
        ]);
        $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stockedPart(User $user, array $attributes): Part
    {
        BranchContext::clear();
        BranchContext::set((int) ($user->home_branch_id ?: Branch::defaultIdFor($user->tenant_id)));

        return Part::create($attributes);
    }

    private function openJob()
    {
        return $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
        ])->assertCreated();
    }

    private function paidOpenBill(float $amount): int
    {
        $billId = $this->openJob()->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor', 'description' => 'Service', 'quantity' => 1, 'unit_price' => $amount,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/payments", [
            'amount' => $amount, 'method' => 'cash',
        ])->assertCreated();

        return $billId;
    }

    private function paidClosedBill(float $amount): int
    {
        $billId = $this->paidOpenBill($amount);
        $this->postJson("/api/bills/{$billId}/close")->assertOk();

        return $billId;
    }
}
