<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\ServiceAddon;
use App\Models\ServiceVehicleClass;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceVehicleClassTest extends TestCase
{
    public function test_seed_creates_car_van_bus_and_attaches_defaults_to_car(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));

        $classes = $this->getJson('/api/service-vehicle-classes')->assertOk()->json();
        $names = collect($classes)->pluck('name')->all();
        $this->assertContains('Car', $names);
        $this->assertContains('Van', $names);
        $this->assertContains('Bus', $names);

        $car = collect($classes)->firstWhere('name', 'Car');
        $addons = $this->getJson('/api/service-addons?service_vehicle_class_id='.$car['id'])->assertOk()->json();
        $this->assertNotEmpty($addons);
        $this->assertTrue(collect($addons)->every(fn ($row) => (int) $row['service_vehicle_class_id'] === (int) $car['id']));
        $this->assertNotNull(collect($addons)->firstWhere('is_full_service', true));
    }

    public function test_each_vehicle_type_can_have_its_own_full_service(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));
        $classes = $this->getJson('/api/service-vehicle-classes')->assertOk()->json();
        $car = collect($classes)->firstWhere('name', 'Car');
        $van = collect($classes)->firstWhere('name', 'Van');

        $wash = $this->postJson('/api/service-addons', [
            'name' => 'Body wash',
            'price' => 1500,
            'service_vehicle_class_id' => $van['id'],
        ])->assertCreated()->json();

        $vanFull = $this->postJson('/api/service-addons', [
            'name' => 'Full service',
            'price' => 12000,
            'is_full_service' => true,
            'service_vehicle_class_id' => $van['id'],
            'included_addon_ids' => [$wash['id']],
        ])->assertCreated()->json();

        $this->assertTrue($vanFull['is_full_service']);
        $carAddons = $this->getJson('/api/service-addons?service_vehicle_class_id='.$car['id'])->assertOk()->json();
        $carFull = collect($carAddons)->firstWhere('is_full_service', true);
        $this->assertNotNull($carFull);
        $this->assertNotSame($carFull['id'], $vanFull['id']);
    }

    public function test_full_service_rejects_inclusions_from_another_vehicle_type(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));
        $classes = $this->getJson('/api/service-vehicle-classes')->assertOk()->json();
        $car = collect($classes)->firstWhere('name', 'Car');
        $van = collect($classes)->firstWhere('name', 'Van');
        $carWash = collect($this->getJson('/api/service-addons?service_vehicle_class_id='.$car['id'])->json())
            ->firstWhere('name', 'Body wash');

        $this->postJson('/api/service-addons', [
            'name' => 'Full service',
            'price' => 9000,
            'is_full_service' => true,
            'service_vehicle_class_id' => $van['id'],
            'included_addon_ids' => [$carWash['id']],
        ])->assertStatus(422)->assertJsonValidationErrors(['included_addon_ids']);
    }

    public function test_service_job_can_set_vehicle_class_and_add_class_full_service(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));
        $classes = $this->getJson('/api/service-vehicle-classes')->assertOk()->json();
        $van = collect($classes)->firstWhere('name', 'Van');

        Sanctum::actingAs($this->ownerFromStaff());
        $wash = $this->postJson('/api/service-addons', [
            'name' => 'Under wash',
            'price' => 2000,
            'service_vehicle_class_id' => $van['id'],
        ])->assertCreated()->json();
        $full = $this->postJson('/api/service-addons', [
            'name' => 'Full service',
            'price' => 15000,
            'is_full_service' => true,
            'service_vehicle_class_id' => $van['id'],
            'included_addon_ids' => [$wash['id']],
        ])->assertCreated()->json();

        Sanctum::actingAs($this->staffUser);
        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'service',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json('id');

        $this->putJson("/api/bills/{$billId}", [
            'service_vehicle_class_id' => $van['id'],
        ])->assertOk()->assertJsonPath('service_vehicle_class_id', $van['id']);

        $item = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'service_addon_id' => $full['id'],
            'quantity' => 1,
        ])->assertCreated()->json('item');

        $this->assertSame('Full service', $item['description']);
        $this->assertEquals(15000, (float) $item['line_total']);
        $this->assertContains('Under wash', $item['included_services']);
    }

    public function test_cannot_delete_vehicle_type_with_services(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));
        $car = collect($this->getJson('/api/service-vehicle-classes')->json())->firstWhere('name', 'Car');
        $this->deleteJson('/api/service-vehicle-classes/'.$car['id'])
            ->assertStatus(422);
    }

    private ?User $staffUser = null;

    private function garageUser(string $role): User
    {
        $keys = [
            'admit_vehicle', 'admit_repair', 'admit_service', 'customers', 'billing',
            'parts_inventory', 'employees_management', 'reports', 'service_ops_report',
        ];
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
        ServiceVehicleClass::ensureDefaultsFor((int) $tenant->id, 'garage');
        ServiceAddon::seedDefaultsFor((int) $tenant->id, 'garage');
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);
        if ($role === 'staff') {
            $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));
            $this->staffUser = $user;
        }

        return $user->load('tenant');
    }

    private function ownerFromStaff(): User
    {
        $tenantId = $this->staffUser?->tenant_id;
        $owner = User::factory()->create([
            'tenant_id' => $tenantId,
            'role' => 'business_owner',
            'status' => 'active',
        ]);

        return $owner->load('tenant');
    }
}
