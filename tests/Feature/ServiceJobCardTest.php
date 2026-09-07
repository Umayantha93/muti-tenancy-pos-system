<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Feature;
use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceJobCardTest extends TestCase
{

    public function test_admit_can_open_a_service_or_repair_job_card(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $repairId = $this->openJob('repair')->json('id');
        $serviceId = $this->openJob('service')->json('id');

        $this->assertSame(Bill::JOB_KIND_REPAIR, Bill::find($repairId)->job_kind);
        $this->assertSame(Bill::JOB_KIND_SERVICE, Bill::find($serviceId)->job_kind);
    }

    public function test_service_job_can_store_next_service_mileage(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $billId = $this->openJob('service')->json('id');

        $this->putJson("/api/bills/{$billId}", [
            'mileage' => 45200,
            'next_service_mileage' => 50200,
        ])->assertOk()
            ->assertJsonPath('mileage', 45200)
            ->assertJsonPath('next_service_mileage', 50200);

        $this->getJson("/api/bills/{$billId}")
            ->assertOk()
            ->assertJsonPath('mileage', 45200)
            ->assertJsonPath('next_service_mileage', 50200);
    }

    public function test_owner_can_manage_service_addons_and_full_service_inclusions(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));

        $addons = $this->getJson('/api/service-addons')->assertOk()->json();
        $this->assertNotEmpty($addons);
        $full = collect($addons)->firstWhere('is_full_service', true);
        $this->assertNotNull($full);
        $this->assertSame('Full service', $full['name']);

        $created = $this->postJson('/api/service-addons', [
            'name' => 'Headlight polish',
            'price' => 1200,
        ])->assertCreated()->json();

        $this->putJson("/api/service-addons/{$created['id']}", [
            'price' => 1500,
        ])->assertOk()->assertJsonPath('price', '1500.00');

        $bodyWash = collect($addons)->firstWhere('name', 'Body wash');
        $this->putJson("/api/service-addons/{$full['id']}", [
            'price' => 9000,
            'is_full_service' => true,
            'included_addon_ids' => [$bodyWash['id'], $created['id']],
        ])->assertOk()
            ->assertJsonPath('price', '9000.00')
            ->assertJsonCount(2, 'inclusions');

        $this->deleteJson("/api/service-addons/{$created['id']}")->assertNoContent();
        $this->assertNull(ServiceAddon::find($created['id']));
    }

    public function test_staff_can_list_addons_but_cannot_mutate_them(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $this->getJson('/api/service-addons')->assertOk();
        $this->postJson('/api/service-addons', [
            'name' => 'Extra wash',
            'price' => 500,
        ])->assertForbidden();
    }

    public function test_service_job_card_adds_addon_lines_with_quantity_and_full_service_inclusions(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $billId = $this->openJob('service')->json('id');
        $addons = $this->getJson('/api/service-addons')->assertOk()->json();
        $wash = collect($addons)->firstWhere('name', 'Body wash');
        $full = collect($addons)->firstWhere('is_full_service', true);

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'service_addon_id' => $wash['id'],
            'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('item.type', 'service_addon')
            ->assertJsonPath('item.description', 'Body wash')
            ->assertJsonPath('item.quantity', '2.00')
            ->assertJsonPath('item.unit_price', '800.00')
            ->assertJsonPath('item.line_total', '1600.00');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'service_addon_id' => $full['id'],
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('item.description', 'Full service')
            ->assertJsonPath('item.unit_price', '8500.00');

        $item = $this->getJson("/api/bills/{$billId}")->assertOk()->json('items.1');
        $this->assertSame('Full service', $item['description']);
        $this->assertContains('Body wash', $item['included_services']);
        $this->assertNotContains('Oil and filter change', $item['included_services']);
    }

    public function test_service_job_card_can_add_inventory_and_deduct_stock(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));
        $part = \App\Models\Part::create([
            'name' => 'Oil Filter', 'brand' => 'Bosch', 'type' => 'filter',
            'price' => 1500, 'cost_price' => 800, 'stock_qty' => 10,
        ]);

        $billId = $this->openJob('service')->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('item.type', 'part')
            ->assertJsonPath('item.quantity', '2.00')
            ->assertJsonPath('item.unit_price', '1500.00');

        $this->assertSame(8, $part->fresh()->stock_qty);
    }

    public function test_bill_profits_split_gross_profit_by_service_and_repair(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $repairId = $this->openJob('repair')->json('id');
        $this->postJson("/api/bills/{$repairId}/items", [
            'type' => 'labor', 'description' => 'Repair labor', 'unit_price' => 4000,
        ])->assertCreated();

        $serviceId = $this->openJob('service')->json('id');
        $addons = $this->getJson('/api/service-addons')->json();
        $wash = collect($addons)->firstWhere('name', 'Body wash');
        $this->postJson("/api/bills/{$serviceId}/items", [
            'type' => 'service_addon',
            'service_addon_id' => $wash['id'],
            'quantity' => 1,
        ])->assertCreated();

        $report = $this->getJson('/api/bill-profits?per_page=50')->assertOk()->json();
        $this->assertEquals(4000, $report['repair_gross_profit']);
        $this->assertEquals(800, $report['service_gross_profit']);
        $this->assertEquals(4800, $report['gross_profit']);
        $this->assertEquals(1, $report['repair_count']);
        $this->assertEquals(1, $report['service_count']);

        $service = $this->getJson('/api/bill-profits?job_kind=service&per_page=50')->assertOk()->json();
        $this->assertEquals(800, $service['total_revenue']);
        $this->assertEquals(800, $service['gross_profit']);
        $this->assertEquals(1, $service['service_count']);
        $this->assertEquals(0, $service['repair_count']);
        $this->assertCount(1, $service['bills']['data']);
        $this->assertSame('service', $service['bills']['data'][0]['job_kind']);

        $repair = $this->getJson('/api/bill-profits?job_kind=repair&per_page=50')->assertOk()->json();
        $this->assertEquals(4000, $repair['total_revenue']);
        $this->assertEquals(4000, $repair['gross_profit']);
        $this->assertEquals(0, $repair['service_count']);
        $this->assertEquals(1, $repair['repair_count']);
        $this->assertCount(1, $repair['bills']['data']);
        $this->assertSame('repair', $repair['bills']['data'][0]['job_kind']);
    }

    private function garageUser(string $role): User
    {
        $keys = [
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports', 'bill_profits',
        ];
        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(), 'business_type' => 'garage', 'owner_name' => fake()->name(),
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);
        if ($role === 'staff') {
            $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));
        }

        return $user;
    }

    private function openJob(string $jobKind = 'repair')
    {
        return $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => $jobKind,
        ])->assertCreated();
    }
}
