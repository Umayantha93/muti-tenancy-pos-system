<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\LaborCategory;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GaragePaintJobsTest extends TestCase
{
    public function test_paint_jobs_are_an_optional_garage_permission(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $catalog = $this->getJson('/api/super-admin/feature-catalog?business_type=garage')->assertOk();
        $this->assertSame('admit_vehicle', $catalog->json('nested.admit_paint'));
        $this->assertNotContains('admit_paint', $catalog->json('defaults'));
        $this->assertContains('admit_paint', $catalog->json('optional'));

        $tenant = $this->postJson('/api/super-admin/tenants', $this->payload())->assertCreated();
        $this->assertFalse(collect($tenant->json('features'))->pluck('key')->contains('admit_paint'));
        $this->assertFalse(
            LaborCategory::withoutGlobalScopes()->where('tenant_id', $tenant->json('id'))->where('catalog', 'paint')->exists()
        );
    }

    public function test_enabling_paint_seeds_paint_catalogs_beside_mechanical_ones(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/super-admin/tenants', $this->payload())->assertCreated();
        $tenantId = $created->json('id');
        $enabled = collect($created->json('features'))->pluck('key');

        $features = collect(BusinessTypes::featuresForType(BusinessTypes::GARAGE))
            ->mapWithKeys(fn (string $key) => [$key => $enabled->contains($key) || $key === 'admit_paint']);

        $this->putJson("/api/super-admin/tenants/{$tenantId}/features", ['features' => $features->all()])
            ->assertOk();

        $catalogs = LaborCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('catalog', 'name');
        $this->assertSame('garage', $catalogs['Brakes']);
        $this->assertSame('paint', $catalogs['Prep']);
        $this->assertTrue(
            ServiceAddon::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('catalog', 'paint')->where('name', 'Bumper respray')->exists()
        );
        $this->assertFalse(
            Part::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', '2K primer grey')->exists()
        );
    }

    public function test_paint_only_garage_can_admit_a_paint_job_and_not_a_repair(): void
    {
        $owner = $this->owner(['admit_vehicle', 'admit_paint', 'billing', 'parts_inventory']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1001',
            'job_kind' => 'repair',
        ])->assertForbidden();

        $panel = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1002',
            'job_kind' => 'paint',
            'paint_mode' => 'panel',
        ])->assertCreated()->json();
        $this->assertSame('paint', $panel['job_kind']);
        $this->assertSame('panel', $panel['paint_mode']);

        $package = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1003',
            'job_kind' => 'paint',
            'paint_mode' => 'package',
        ])->assertCreated()->json();
        $this->assertSame('package', $package['paint_mode']);
    }

    public function test_paint_job_uses_paint_catalog_panel_composer_and_package_price(): void
    {
        $owner = $this->owner(['admit_vehicle', 'admit_repair', 'admit_paint', 'billing', 'parts_inventory']);
        Sanctum::actingAs($owner);
        $tenantId = (int) $owner->tenant_id;

        $paintLabor = collect($this->getJson('/api/labor-catalog?catalog=paint')->assertOk()->json())->pluck('name');
        $garageLabor = collect($this->getJson('/api/labor-catalog')->assertOk()->json())->pluck('name');
        $this->assertTrue($paintLabor->contains('Prep'));
        $this->assertFalse($paintLabor->contains('Brakes'));
        $this->assertTrue($garageLabor->contains('Brakes'));
        $this->assertFalse($garageLabor->contains('Prep'));

        $maskingId = LaborCategory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('catalog', 'paint')
            ->firstOrFail()
            ->items()
            ->where('name', 'Masking')
            ->value('id');

        $part = new Part;
        $part->forceFill([
            'tenant_id' => $tenantId,
            'name' => 'Booth primer',
            'brand' => 'Shop',
            'type' => 'Primer',
            'price' => 10,
            'cost_price' => 4,
            'stock_qty' => 1000,
            'stock_unit' => 'ml',
        ])->save();

        $panelId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-2001',
            'job_kind' => 'paint',
            'paint_mode' => 'panel',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$panelId}/items/panel", [
            'panel_name' => 'Door',
            'labor' => [['labor_item_id' => $maskingId, 'quantity' => 2]],
            'materials' => [['part_id' => $part->id, 'quantity' => 50]],
        ])->assertCreated();

        $repairId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-2002',
            'job_kind' => 'repair',
        ])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$repairId}/items", [
            'type' => 'labor',
            'labor_item_id' => $maskingId,
            'quantity' => 1,
        ])->assertStatus(422);

        $this->getJson('/api/service-addons?catalog=paint')->assertOk();
        $addonId = ServiceAddon::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('catalog', 'paint')
            ->where('name', 'Bumper respray')
            ->value('id');
        $packageId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-2003',
            'job_kind' => 'paint',
            'paint_mode' => 'package',
        ])->assertCreated()->json('id');
        $line = $this->postJson("/api/bills/{$packageId}/items", [
            'type' => 'service_addon',
            'service_addon_id' => $addonId,
        ])->assertCreated()->json('item');
        $this->assertSame('25000.00', $line['unit_price']);
    }

    public function test_garage_paint_job_uses_paint_progress_steps(): void
    {
        $owner = $this->owner(['admit_vehicle', 'admit_paint', 'billing', 'job_progress']);
        Sanctum::actingAs($owner);
        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-3001',
            'job_kind' => 'paint',
        ])->assertCreated()->json('id');

        $this->putJson("/api/bills/{$billId}/progress", ['step' => 'painting'])->assertOk()
            ->assertJsonPath('current_step', 'painting');
    }

    /**
     * @param  list<string>  $keys
     */
    private function owner(array $keys): User
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

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'business_name' => 'Mixed Booth',
            'business_type' => BusinessTypes::GARAGE,
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ];
    }
}
