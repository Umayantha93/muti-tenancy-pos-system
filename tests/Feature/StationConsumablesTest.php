<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Models\StockIssue;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StationConsumablesTest extends TestCase
{
    public function test_tin_issue_deducts_stock_and_spreads_cost_over_billed_washes(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $engineWash = ServiceAddon::create(['name' => 'Engine wash', 'price' => 0, 'sort_order' => 10, 'active' => true]);
        $tin = Part::create([
            'name' => 'Hypower 20L', 'brand' => 'Hypower', 'type' => 'chemical',
            'price' => 22000, 'cost_price' => 18000, 'stock_qty' => 3,
        ]);

        $this->postJson('/api/station-consumables', [
            'part_id' => $tin->id,
            'quantity' => 1,
            'service_names' => ['Engine wash'],
        ])->assertCreated()->assertJsonPath('cost', 18000);
        $this->assertEquals(2, $tin->fresh()->stock_qty);

        $this->travel(1)->minutes();
        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal', 'customer_phone' => '0771234567',
            'number_plate' => 'CAB-9001', 'job_kind' => 'service',
        ])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $engineWash->id, 'quantity' => 2, 'unit_price' => 1200])
            ->assertCreated();

        $this->getJson('/api/station-consumables')
            ->assertOk()
            ->assertJsonPath('open.0.washes', 2)
            ->assertJsonPath('open.0.revenue', 2400)
            ->assertJsonPath('open.0.cost_per_wash', 9000);

        $this->travel(1)->minutes();
        $this->postJson('/api/station-consumables', [
            'part_id' => $tin->id, 'quantity' => 1, 'service_names' => ['Engine wash'],
        ])->assertStatus(409);

        $second = $this->postJson('/api/station-consumables', [
            'part_id' => $tin->id, 'quantity' => 1, 'service_names' => ['Engine wash'], 'close_previous' => true,
        ])->assertCreated()->json('id');
        $this->assertEquals(1, $tin->fresh()->stock_qty);
        $this->assertSame(1, StockIssue::query()->whereNotNull('closed_at')->count());

        $report = $this->getJson('/api/reports/service-ops')->assertOk()->json();
        $row = collect($report['rows'])->firstWhere('name', 'Engine wash');
        $this->assertEquals(18000, $row['consumable_cost']);
        $this->assertEquals(2400 - 18000, $row['profit']);

        $this->postJson("/api/station-consumables/{$second}/close", ['returned_qty' => 1])
            ->assertOk()
            ->assertJsonPath('cost', 0);
        $this->assertEquals(2, $tin->fresh()->stock_qty);
    }

    public function test_tin_issued_today_can_be_voided_and_stock_returns(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $tin = Part::create([
            'name' => 'Shampoo 20L', 'brand' => 'Wash', 'type' => 'chemical',
            'price' => 9000, 'cost_price' => 7000, 'stock_qty' => 2,
        ]);

        $id = $this->postJson('/api/station-consumables', [
            'part_id' => $tin->id, 'quantity' => 1, 'service_names' => ['Body wash'],
        ])->assertCreated()->json('id');
        $this->assertEquals(1, $tin->fresh()->stock_qty);

        $this->deleteJson("/api/station-consumables/{$id}")->assertNoContent();
        $this->assertEquals(2, $tin->fresh()->stock_qty);
        $this->assertSame(0, StockIssue::query()->count());
    }

    public function test_station_consumables_needs_the_feature(): void
    {
        Sanctum::actingAs($this->garageUser([]));

        $this->getJson('/api/station-consumables')->assertForbidden();
    }

    private function garageUser(?array $extra = null): User
    {
        $keys = [
            'admit_vehicle', 'admit_service', 'customers', 'billing', 'parts_inventory', 'reports',
            ...($extra ?? ['station_consumables', 'service_ops_report']),
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
