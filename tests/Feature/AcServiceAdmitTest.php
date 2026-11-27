<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\LaborItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcServiceAdmitTest extends TestCase
{
    public function test_ac_service_onboard_enables_both_intakes_and_seeds_labor(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $catalog = $this->getJson('/api/super-admin/feature-catalog?business_type=ac_service')->assertOk();
        $this->assertContains('admit_appliance', $catalog->json('defaults'));
        $this->assertContains('admit_repair', $catalog->json('defaults'));
        $this->assertContains('admit_service', $catalog->json('defaults'));
        $this->assertContains('job_board', $catalog->json('optional'));
        $this->assertNotContains('admit_appliance', $catalog->json('optional'));
        $this->assertSame('ac-pro', BusinessTypes::defaultPlan(BusinessTypes::AC_SERVICE));

        $tenant = $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Cool Air',
            'business_type' => BusinessTypes::AC_SERVICE,
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ])->assertCreated();

        $keys = collect($tenant->json('features'))->pluck('key');
        $this->assertTrue($keys->contains('admit_vehicle'));
        $this->assertTrue($keys->contains('admit_appliance'));
        $this->assertTrue($keys->contains('admit_repair'));
        $this->assertTrue($keys->contains('admit_service'));
        $this->assertFalse($keys->contains('job_board'));
        $this->assertTrue(
            LaborItem::query()->where('tenant_id', $tenant->json('id'))->where('name', 'Gas charge')->exists()
        );
    }

    public function test_ac_shop_admits_a_vehicle_and_an_appliance(): void
    {
        $owner = $this->owner(BusinessTypes::AC_SERVICE, [
            'admit_vehicle', 'admit_repair', 'admit_service', 'admit_appliance', 'customers', 'billing',
        ]);
        Sanctum::actingAs($owner);

        $vehicle = $this->postJson('/api/bills', [
            'number_plate' => 'CAB-9001',
            'make' => 'Toyota',
            'job_kind' => 'repair',
            'asset_kind' => 'vehicle',
            'customer_name' => 'Nimal',
            'customer_phone' => '0771112233',
        ])->assertCreated();
        $this->assertSame('vehicle', $vehicle->json('vehicle.asset_kind'));
        $this->assertNull($vehicle->json('service_place'));

        $appliance = $this->postJson('/api/bills', [
            'number_plate' => 'SN-4412',
            'make' => 'LG',
            'model' => 'Dual Cool',
            'job_kind' => 'service',
            'asset_kind' => 'appliance',
            'appliance_type' => 'split_ac',
            'fault_description' => 'Not cooling',
            'service_place' => 'on_site',
            'customer_name' => 'Kamal',
            'customer_phone' => '0772223344',
        ])->assertCreated();
        $this->assertSame('appliance', $appliance->json('vehicle.asset_kind'));
        $this->assertSame('split_ac', $appliance->json('vehicle.appliance_type'));
        $this->assertSame('SN-4412', $appliance->json('vehicle.number_plate'));
        $this->assertSame('on_site', $appliance->json('service_place'));
        $this->assertSame('service', $appliance->json('job_kind'));

        $this->postJson('/api/bills', [
            'number_plate' => 'CAB-9002',
            'job_kind' => 'service',
            'asset_kind' => 'vehicle',
            'customer_name' => 'Nimal',
            'customer_phone' => '0771112233',
        ])->assertStatus(422)->assertJsonPath('errors.job_kind.0', 'Vehicle jobs are repairs only.');
    }

    public function test_home_ac_services_apply_only_to_appliance_service_jobs(): void
    {
        $owner = $this->owner(BusinessTypes::AC_SERVICE, [
            'admit_vehicle', 'admit_repair', 'admit_service', 'admit_appliance', 'customers', 'billing',
        ]);
        Sanctum::actingAs($owner);

        $addons = collect($this->getJson('/api/service-addons')->assertOk()->json());
        $general = $addons->firstWhere('name', 'General service');
        $this->assertNotNull($general);
        $this->assertSame('ac', $general['catalog']);
        $this->assertEquals(3500, (float) $general['price']);
        $this->assertTrue($addons->contains(fn (array $row) => $row['name'] === 'Jet service'));

        $appliance = $this->postJson('/api/bills', [
            'number_plate' => 'SN-HOME-1',
            'asset_kind' => 'appliance',
            'appliance_type' => 'split_ac',
            'job_kind' => 'service',
            'customer_name' => 'Ama',
            'customer_phone' => '0773334455',
        ])->assertCreated();

        $this->postJson('/api/bills/'.$appliance->json('id').'/items', [
            'type' => 'service_addon',
            'service_addon_id' => $general['id'],
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('item.description', 'General service')
            ->assertJsonPath('item.unit_price', '3500.00');

        $repair = $this->postJson('/api/bills', [
            'number_plate' => 'SN-HOME-2',
            'asset_kind' => 'appliance',
            'appliance_type' => 'fridge',
            'job_kind' => 'repair',
            'customer_name' => 'Ama',
            'customer_phone' => '0773334455',
        ])->assertCreated();

        $this->postJson('/api/bills/'.$repair->json('id').'/items', [
            'type' => 'service_addon',
            'service_addon_id' => $general['id'],
        ])->assertStatus(422)->assertJsonPath('errors.service_addon_id.0', 'Home AC services are only for appliance service jobs.');

        $vehicle = $this->postJson('/api/bills', [
            'number_plate' => 'CAA-4401',
            'asset_kind' => 'vehicle',
            'job_kind' => 'repair',
            'customer_name' => 'Ama',
            'customer_phone' => '0773334456',
        ])->assertCreated();

        $this->postJson('/api/bills/'.$vehicle->json('id').'/items', [
            'type' => 'service_addon',
            'service_addon_id' => $general['id'],
        ])->assertStatus(422)->assertJsonPath('errors.service_addon_id.0', 'Home AC services are only for appliance service jobs.');
    }

    public function test_a_shop_can_add_an_appliance_type(): void
    {
        $owner = $this->owner(BusinessTypes::AC_SERVICE, [
            'admit_vehicle', 'admit_repair', 'admit_service', 'admit_appliance', 'customers', 'billing',
        ]);
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/appliance-types', ['name' => 'Water dispenser'])->assertCreated();
        $this->assertSame('water_dispenser', $created->json('key'));
        $this->assertFalse($created->json('built_in'));

        $this->postJson('/api/bills', [
            'number_plate' => 'SN-WD-1',
            'asset_kind' => 'appliance',
            'appliance_type' => 'water_dispenser',
            'job_kind' => 'repair',
            'customer_name' => 'Nimal',
            'customer_phone' => '0775556677',
        ])->assertCreated()
            ->assertJsonPath('vehicle.appliance_type', 'water_dispenser')
            ->assertJsonPath('vehicle.appliance_type_name', 'Water dispenser');

        $this->postJson('/api/bills', [
            'number_plate' => 'SN-NOPE',
            'asset_kind' => 'appliance',
            'appliance_type' => 'not_a_real_type',
            'job_kind' => 'repair',
            'customer_name' => 'Nimal',
            'customer_phone' => '0775556678',
        ])->assertStatus(422)->assertJsonPath('errors.appliance_type.0', 'Choose an appliance from the list.');
    }

    public function test_other_shops_cannot_admit_an_appliance(): void
    {
        $garage = $this->owner(BusinessTypes::GARAGE, ['admit_vehicle', 'admit_repair', 'customers', 'billing']);
        $devices = $this->owner(BusinessTypes::DEVICE_REPAIR, ['admit_vehicle', 'customers', 'billing']);
        Sanctum::actingAs($garage);
        $this->postJson('/api/bills', [
            'number_plate' => 'FRIDGE-1',
            'asset_kind' => 'appliance',
            'appliance_type' => 'fridge',
        ])->assertStatus(422)->assertJsonPath('errors.asset_kind.0', 'Appliance admissions are only for A/C repair shops.');

        Sanctum::actingAs($devices);
        $this->postJson('/api/bills', [
            'number_plate' => 'FRIDGE-2',
            'asset_kind' => 'appliance',
            'appliance_type' => 'fridge',
        ])->assertStatus(422)->assertJsonPath('errors.asset_kind.0', 'Appliance admissions are only for A/C repair shops.');
    }

    /**
     * @param  list<string>  $keys
     */
    private function owner(string $type, array $keys): User
    {
        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(),
            'business_type' => $type,
            'owner_name' => fake()->name(),
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
        ]);
    }
}
