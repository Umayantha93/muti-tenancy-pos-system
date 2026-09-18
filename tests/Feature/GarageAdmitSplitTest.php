<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GarageAdmitSplitTest extends TestCase
{
    public function test_garage_onboard_enables_repair_and_service_under_admit(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $catalog = $this->getJson('/api/super-admin/feature-catalog?business_type=garage')->assertOk();
        $this->assertSame('admit_vehicle', $catalog->json('nested.admit_repair'));
        $this->assertSame('admit_vehicle', $catalog->json('nested.admit_service'));
        $this->assertContains('admit_repair', $catalog->json('defaults'));
        $this->assertContains('admit_service', $catalog->json('defaults'));
        $this->assertContains('job_board', $catalog->json('optional'));

        $tenant = $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Split Garage',
            'business_type' => BusinessTypes::GARAGE,
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ])->assertCreated();

        $keys = collect($tenant->json('features'))->pluck('key');
        $this->assertTrue($keys->contains('admit_vehicle'));
        $this->assertTrue($keys->contains('admit_repair'));
        $this->assertTrue($keys->contains('admit_service'));
        $this->assertFalse($keys->contains('job_board'));
    }

    public function test_super_admin_cannot_save_admit_without_a_job_kind(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $tenantId = $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Kind Lock Garage',
            'business_type' => BusinessTypes::GARAGE,
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ])->assertCreated()->json('id');

        $features = collect(BusinessTypes::featuresForType(BusinessTypes::GARAGE))
            ->mapWithKeys(fn (string $key) => [$key => in_array($key, ['admit_vehicle', 'customers', 'billing'], true)]);

        $this->putJson("/api/super-admin/tenants/{$tenantId}/features", ['features' => $features->all()])
            ->assertStatus(422)
            ->assertJsonPath('errors.features.0', 'Choose Repair, Service, or both.');
    }

    public function test_service_only_garage_rejects_repair_jobs_with_403(): void
    {
        $owner = $this->garageOwner(['admit_vehicle', 'admit_service', 'customers', 'billing']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/bills', [
            'number_plate' => 'CAB-5511',
            'admission_date' => now()->toDateString(),
            'job_kind' => 'repair',
        ])->assertForbidden();

        $service = $this->postJson('/api/bills', [
            'number_plate' => 'CAB-5512',
            'admission_date' => now()->toDateString(),
            'job_kind' => 'service',
        ])->assertCreated()->json();
        $this->assertSame('service', $service['job_kind']);

        $this->postJson('/api/bills/instant', ['customer_name' => 'Walk-in'])->assertCreated();
    }

    public function test_tyre_catalog_does_not_expose_garage_admit_split(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $keys = collect($this->getJson('/api/super-admin/feature-catalog?business_type=tyre')->json('features'))->pluck('key');
        $this->assertFalse($keys->contains('admit_repair'));
        $this->assertFalse($keys->contains('admit_service'));
        $this->assertTrue($keys->contains('admit_vehicle'));
        $this->assertTrue($keys->contains('job_bookings'));
    }

    /**
     * @param  list<string>  $keys
     */
    private function garageOwner(array $keys): User
    {
        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
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

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
        ]);
    }
}
