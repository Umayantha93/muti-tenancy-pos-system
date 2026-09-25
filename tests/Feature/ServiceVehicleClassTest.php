<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Feature;
use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceVehicleClassTest extends TestCase
{
    public function test_owner_types_in_vehicle_categories(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));

        $this->getJson('/api/service-vehicle-classes')->assertOk()->assertJsonCount(0);

        $tata = $this->postJson('/api/service-vehicle-classes', ['name' => 'Tata bus'])->assertCreated()->json();
        $this->postJson('/api/service-vehicle-classes', ['name' => 'SUV'])->assertCreated();
        $this->postJson('/api/service-vehicle-classes', ['name' => 'tata bus'])->assertStatus(422);

        $this->putJson("/api/service-vehicle-classes/{$tata['id']}", ['name' => 'Tata / Leyland bus'])
            ->assertOk()
            ->assertJsonPath('name', 'Tata / Leyland bus');

        $names = collect($this->getJson('/api/service-vehicle-classes')->json())->pluck('name')->all();
        $this->assertSame(['Tata / Leyland bus', 'SUV'], $names);

        $this->deleteJson("/api/service-vehicle-classes/{$tata['id']}")->assertNoContent();
        $this->getJson('/api/service-vehicle-classes')->assertJsonCount(1);
    }

    public function test_staff_can_list_categories_but_not_change_them(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $this->getJson('/api/service-vehicle-classes')->assertOk();
        $this->postJson('/api/service-vehicle-classes', ['name' => 'Van'])->assertForbidden();
    }

    public function test_service_job_stores_category_and_uses_the_typed_price(): void
    {
        $owner = $this->garageUser('business_owner');
        Sanctum::actingAs($owner);
        $van = $this->postJson('/api/service-vehicle-classes', ['name' => 'Van'])->assertCreated()->json();
        $wash = $this->postJson('/api/service-addons', ['name' => 'Under wash'])->assertCreated()->json();

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'service',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json('id');

        $this->putJson("/api/bills/{$billId}", ['service_vehicle_class_id' => $van['id']])
            ->assertOk()
            ->assertJsonPath('service_vehicle_class_id', $van['id']);

        $item = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'service_addon_id' => $wash['id'],
            'quantity' => 1,
            'unit_price' => 2000,
        ])->assertCreated()->json('item');

        $this->assertSame('Under wash', $item['description']);
        $this->assertEquals(2000, (float) $item['line_total']);
        $this->assertEquals(0, (float) ServiceAddon::find($wash['id'])->price, 'The service list keeps no price.');

        $this->deleteJson("/api/service-vehicle-classes/{$van['id']}")->assertNoContent();
        $this->assertNull(Bill::find($billId)->service_vehicle_class_id);
    }

    public function test_vehicle_type_can_hide_a_service_or_prefill_its_price(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));
        $van = $this->postJson('/api/service-vehicle-classes', ['name' => 'Van'])->assertCreated()->json();
        $bike = $this->postJson('/api/service-vehicle-classes', ['name' => 'Motorbike'])->assertCreated()->json();
        $wash = $this->postJson('/api/service-addons', ['name' => 'Body wash'])->assertCreated()->json();
        $engine = $this->postJson('/api/service-addons', ['name' => 'Engine wash'])->assertCreated()->json();

        $this->putJson("/api/service-addons/{$wash['id']}/vehicle-classes/{$van['id']}", ['offered' => true, 'price' => 1500])
            ->assertOk()
            ->assertJsonPath('vehicle_prices.0.price', '1500.00');
        $this->putJson("/api/service-addons/{$engine['id']}/vehicle-classes/{$bike['id']}", ['offered' => false])->assertOk();

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'service',
        ])->assertCreated()->json('id');
        $this->putJson("/api/bills/{$billId}", ['service_vehicle_class_id' => $van['id']])->assertOk();

        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $wash['id'], 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('item.unit_price', '1500.00');
        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $wash['id'], 'quantity' => 1, 'unit_price' => 1800])
            ->assertCreated()
            ->assertJsonPath('item.unit_price', '1800.00');
        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $engine['id'], 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('item.unit_price', '0.00');

        $this->putJson("/api/bills/{$billId}", ['service_vehicle_class_id' => $bike['id']])->assertOk();
        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $engine['id'], 'quantity' => 1, 'unit_price' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_addon_id']);

        $this->putJson("/api/service-addons/{$wash['id']}/vehicle-classes/{$van['id']}", ['offered' => true, 'price' => null])
            ->assertOk()
            ->assertJsonCount(0, 'vehicle_prices');
    }

    private function garageUser(string $role): User
    {
        $keys = ['admit_vehicle', 'admit_repair', 'admit_service', 'customers', 'billing'];
        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(
                ['key' => $key],
                ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0]
            )
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(),
            'business_type' => 'garage',
            'owner_name' => fake()->name(),
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);
        if ($role === 'staff') {
            $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));
        }

        return $user->load('tenant');
    }
}
