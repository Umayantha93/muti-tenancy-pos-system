<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BranchContext;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstantBillTest extends TestCase
{
    public function test_instant_bill_opens_without_vehicle_and_sells_stock(): void
    {
        [, $owner] = $this->garageTenant();
        Sanctum::actingAs($owner);
        BranchContext::clear();

        $part = Part::create([
            'name' => 'Oil Filter', 'brand' => 'Bosch', 'type' => 'filter',
            'price' => 3200, 'cost_price' => 2000, 'stock_qty' => 5,
        ]);

        $bill = $this->postJson('/api/bills/instant', [
            'customer_name' => 'Walk-in',
            'customer_phone' => '0771112233',
        ])->assertCreated()->json();

        $this->assertStringStartsWith('INST-', $bill['bill_number']);
        $this->assertSame('parts_sale', $bill['job_kind']);
        $this->assertNull($bill['vehicle_id'] ?? null);
        $this->assertSame('Walk-in', $bill['customer']['name']);
        $this->assertSame([], $bill['employees']);

        $this->postJson('/api/bills/'.$bill['id'].'/items', [
            'type' => 'part',
            'part_id' => $part->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/bills/'.$bill['id'].'/items', [
            'type' => 'labor',
            'description' => 'Filter fitment',
            'quantity' => 1,
            'unit_price' => 1500,
        ])->assertCreated();

        $this->postJson('/api/bills/'.$bill['id'].'/items', [
            'type' => 'discount',
            'description' => 'Walk-in discount',
            'quantity' => 1,
            'unit_price' => 200,
        ])->assertCreated();

        $fresh = $this->getJson('/api/bills/'.$bill['id'])->assertOk()->json();
        $this->assertCount(3, $fresh['items']);
        $this->assertNull($fresh['vehicle']);
        $this->assertSame(4, $part->fresh()->stock_qty);
        $this->assertEquals(4700, (float) $fresh['subtotal']);
        $this->assertEquals(200, (float) $fresh['total_deductions']);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_garage_instant_checkout_accepts_custom_lines(): void
    {
        [, $owner] = $this->garageTenant();
        Sanctum::actingAs($owner);
        BranchContext::clear();

        $part = Part::create([
            'name' => 'Brake pad', 'brand' => 'Bosch', 'type' => 'brake',
            'price' => 4500, 'cost_price' => 2800, 'stock_qty' => 8,
        ]);

        $bill = $this->postJson('/api/bills/instant', [
            'customer_name' => 'Counter',
            'items' => [
                ['part_id' => $part->id, 'quantity' => 1],
                ['type' => 'labor', 'description' => 'Used grease', 'unit_price' => 300, 'quantity' => 2],
            ],
            'payment_method' => 'cash',
            'payment_amount' => 5100,
        ])->assertCreated()->json();

        $this->assertCount(2, $bill['items']);
        $this->assertEquals(5100, (float) $bill['subtotal']);
        $this->assertEquals(0, (float) $bill['balance_due']);
        $this->assertSame(7, $part->fresh()->stock_qty);
        $custom = collect($bill['items'])->firstWhere('type', 'service_addon');
        $this->assertSame('Used grease', $custom['description']);
        $this->assertEquals(2, (float) $custom['quantity']);
        $this->assertEquals(600, (float) $custom['line_total']);
    }

    public function test_garage_instant_checkout_sells_inventory_in_one_step(): void
    {
        [, $owner] = $this->garageTenant();
        Sanctum::actingAs($owner);
        BranchContext::clear();

        $part = Part::create([
            'name' => 'Brake pad', 'brand' => 'Bosch', 'type' => 'brake',
            'price' => 4500, 'cost_price' => 2800, 'stock_qty' => 8,
        ]);

        $bill = $this->postJson('/api/bills/instant', [
            'customer_name' => 'Counter',
            'items' => [
                ['part_id' => $part->id, 'quantity' => 2],
            ],
            'payment_method' => 'cash',
            'payment_amount' => 9000,
        ])->assertCreated()->json();

        $this->assertStringStartsWith('INST-', $bill['bill_number']);
        $this->assertCount(1, $bill['items']);
        $this->assertCount(1, $bill['payments']);
        $this->assertEquals(0, (float) $bill['balance_due']);
        $this->assertSame(6, $part->fresh()->stock_qty);
        $this->assertSame([], $bill['employees']);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function garageTenant(): array
    {
        $features = collect([
            'admit_vehicle', 'customers', 'billing', 'parts_inventory', 'bill_profits',
        ])->map(fn (string $key) => Feature::firstOrCreate(
            ['key' => $key],
            ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0],
        ));

        $tenant = Tenant::create([
            'business_name' => fake()->company(),
            'business_type' => 'garage',
            'owner_name' => fake()->name(),
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
        ]);

        return [$tenant, $owner];
    }
}
