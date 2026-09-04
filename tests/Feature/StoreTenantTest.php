<?php

namespace Tests\Feature;

use App\Models\LaborCategory;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Models\User;
use App\Support\BusinessTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_onboards_a_store_without_workshop_or_repair(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $catalog = $this->getJson('/api/super-admin/feature-catalog?business_type=store')->assertOk();
        $catalogKeys = collect($catalog->json('features'))->pluck('key');
        $this->assertTrue($catalogKeys->contains('parts_inventory'));
        $this->assertTrue($catalogKeys->contains('repair_bills'));
        $this->assertFalse($catalogKeys->contains('admit_vehicle'));

        $store = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            'Kandy Mobile Mart',
            BusinessTypes::STORE,
            'store@shop.test',
        ))->assertCreated()
            ->assertJsonPath('business_type', 'store')
            ->assertJsonPath('plan', 'store-pro');

        $keys = collect($store->json('features'))->pluck('key');
        $this->assertTrue($keys->contains('parts_inventory'));
        $this->assertTrue($keys->contains('billing'));
        $this->assertFalse($keys->contains('admit_vehicle'));
        $this->assertFalse($keys->contains('repair_bills'));
        $this->assertFalse($keys->contains('retail_pos'));

        $tenantId = $store->json('id');
        $this->assertSame(0, LaborCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
        $this->assertSame(0, ServiceAddon::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
    }

    public function test_store_counter_sale_decrements_stock_and_uses_sale_prefix(): void
    {
        [, $owner] = $this->storeOwner();
        Sanctum::actingAs($owner);

        $part = Part::create([
            'name' => 'USB-C charger 25W',
            'brand' => 'Baseus',
            'type' => 'Charger',
            'barcode' => '7441234567890',
            'price' => 2450,
            'cost_price' => 1600,
            'stock_qty' => 10,
        ]);

        $sale = $this->postJson('/api/part-sales', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'items' => [['part_id' => $part->id, 'quantity' => 2]],
            'payment_amount' => 4900,
            'payment_method' => 'cash',
            'discount' => 0,
        ])->assertCreated();

        $this->assertSame('SALE-', substr($sale->json('bill.bill_number'), 0, 5));
        $this->assertSame('parts_sale', $sale->json('bill.job_kind'));
        $this->assertEquals(8, $part->fresh()->stock_qty);
    }

    public function test_store_cannot_open_repair_until_super_admin_enables_it(): void
    {
        [$tenant, $owner] = $this->storeOwner();
        Sanctum::actingAs($owner);

        $this->postJson('/api/bills', [
            'customer_name' => 'Kamal',
            'job_kind' => 'repair',
        ])->assertStatus(422);

        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $features = collect(BusinessTypes::featuresForType(BusinessTypes::STORE))
            ->mapWithKeys(fn (string $key) => [$key => $key === 'repair_bills' || in_array($key, BusinessTypes::defaults(BusinessTypes::STORE), true)]);

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/features", [
            'features' => $features->all(),
        ])->assertOk();

        $this->assertTrue(
            $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key')->contains('repair_bills')
        );

        Sanctum::actingAs($owner->fresh());
        $bill = $this->postJson('/api/bills', [
            'customer_name' => 'Kamal',
            'customer_phone' => '0775556677',
            'notes' => 'A15 · screen crack',
            'job_kind' => 'repair',
        ])->assertCreated()->json();

        $this->assertSame('repair', $bill['job_kind']);
        $this->assertSame('REP-', substr($bill['bill_number'], 0, 4));

        $saleId = $this->postJson('/api/part-sales', [
            'items' => [['part_id' => Part::create([
                'name' => 'Screen',
                'brand' => 'OEM',
                'type' => 'Spare',
                'price' => 4000,
                'cost_price' => 2500,
                'stock_qty' => 3,
            ])->id, 'quantity' => 1]],
            'payment_amount' => 4000,
        ])->assertCreated()->json('bill.id');

        $this->postJson("/api/bills/{$saleId}/items", [
            'type' => 'labor',
            'description' => 'Should not land on a sale',
            'unit_price' => 500,
        ])->assertStatus(422);

        $this->postJson("/api/bills/{$bill['id']}/items", [
            'type' => 'labor',
            'description' => 'Screen replacement',
            'unit_price' => 8500,
        ])->assertCreated();

        $this->getJson('/api/bill-profits?job_kind=repair')->assertOk()
            ->assertJsonPath('repair_count', 1);
    }

    /**
     * @return array{0: \App\Models\Tenant, 1: User}
     */
    private function storeOwner(): array
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $tenantId = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            'Matara Spare Parts',
            BusinessTypes::STORE,
            fake()->unique()->safeEmail(),
        ))->assertCreated()->json('id');

        $owner = User::query()->where('tenant_id', $tenantId)->where('role', 'business_owner')->firstOrFail();

        return [$owner->tenant, $owner];
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardPayload(string $businessName, string $businessType, string $ownerEmail): array
    {
        return [
            'business_name' => $businessName,
            'business_type' => $businessType,
            'owner_name' => 'Shop Owner',
            'owner_phone' => '0771002003',
            'owner_email' => $ownerEmail,
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ];
    }
}
