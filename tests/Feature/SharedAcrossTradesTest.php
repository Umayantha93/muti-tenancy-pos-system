<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedAcrossTradesTest extends TestCase
{
    public function test_store_gets_cash_up_on_by_default_and_garage_keeps_it_off(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/super-admin/tenants', $this->onboardPayload('Store Cash', BusinessTypes::STORE))->assertCreated();
        $this->assertTrue(collect($store->json('features'))->pluck('key')->contains('cash_up'));
        $this->assertFalse(collect($this->getJson('/api/super-admin/feature-catalog?business_type=store')->json('optional'))->contains('cash_up'));

        $garage = $this->postJson('/api/super-admin/tenants', $this->onboardPayload('Garage Cash', BusinessTypes::GARAGE))->assertCreated();
        $this->assertFalse(collect($garage->json('features'))->pluck('key')->contains('cash_up'));
        $this->assertContains('cash_up', $this->getJson('/api/super-admin/feature-catalog?business_type=garage')->json('optional'));
    }

    public function test_whatsapp_share_is_off_until_enabled(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $garageCatalog = $this->getJson('/api/super-admin/feature-catalog?business_type=garage')->assertOk();
        $this->assertContains('bill_whatsapp', $garageCatalog->json('optional'));
        $this->assertSame('billing', $garageCatalog->json('nested.bill_whatsapp'));
        $this->assertContains('bill_whatsapp', $this->getJson('/api/super-admin/feature-catalog?business_type=store')->json('optional'));

        $garage = $this->postJson('/api/super-admin/tenants', $this->onboardPayload('Garage WhatsApp', BusinessTypes::GARAGE))->assertCreated();
        $this->assertFalse(collect($garage->json('features'))->pluck('key')->contains('bill_whatsapp'));
    }

    public function test_cash_up_compares_drawer_to_todays_takings(): void
    {
        [, $owner] = $this->storeOwner();
        Sanctum::actingAs($owner);

        $part = Part::create([
            'name' => 'USB cable',
            'brand' => 'Generic',
            'type' => 'Cable',
            'price' => 500,
            'cost_price' => 200,
            'stock_qty' => 10,
        ]);
        $this->postJson('/api/part-sales', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'items' => [['part_id' => $part->id, 'quantity' => 1]],
            'payment_amount' => 500,
            'payment_method' => 'cash',
        ])->assertCreated();

        $preview = $this->getJson('/api/cash-up')->assertOk();
        $this->assertEquals(500, (float) $preview->json('expected.expected_cash'));

        $closed = $this->postJson('/api/cash-up', [
            'counted_cash' => 480,
            'counted_card' => 0,
            'counted_bank' => 0,
            'counted_cheque' => 0,
        ])->assertOk();
        $this->assertEquals(-20, (float) $closed->json('difference.cash'));
        $this->assertNotEmpty($closed->json('cash_up.closed_at'));
    }

    public function test_outstanding_lists_ageing_and_share_token(): void
    {
        $owner = $this->garageOwner();
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/customers', [
            'name' => 'Kamal Silva',
            'phone' => '0778881234',
        ])->assertCreated();

        $bill = $this->postJson('/api/bills/instant', [
            'customer_name' => 'Kamal Silva',
            'customer_phone' => '0778881234',
        ])->assertCreated()->json();
        $this->postJson('/api/bills/'.$bill['id'].'/items', [
            'type' => 'labor',
            'description' => 'Clutch',
            'quantity' => 1,
            'unit_price' => 4000,
        ])->assertCreated();

        $row = $this->getJson('/api/customers/outstanding')->assertOk()->json('data.0');
        $this->assertSame($created->json('id'), $row['id']);
        $this->assertGreaterThan(0, (float) $row['outstanding_balance']);
        $this->assertSame(0, (int) $row['outstanding_days']);
        $this->assertNotEmpty($row['oldest_unpaid_bill']['share_token']);
    }

    public function test_garage_cash_up_is_forbidden_until_enabled(): void
    {
        $owner = $this->garageOwner();
        Sanctum::actingAs($owner);
        $this->getJson('/api/cash-up')->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function storeOwner(): array
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);
        $tenantId = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            'Matara Counter',
            BusinessTypes::STORE,
        ))->assertCreated()->json('id');
        $owner = User::query()->where('tenant_id', $tenantId)->where('role', 'business_owner')->firstOrFail();

        return [$owner->tenant, $owner];
    }

    private function garageOwner(): User
    {
        $keys = ['customers', 'billing', 'admit_vehicle'];
        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => 'Ageing Garage',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardPayload(string $businessName, string $businessType): array
    {
        return [
            'business_name' => $businessName,
            'business_type' => $businessType,
            'owner_name' => 'Shop Owner',
            'owner_phone' => '0771002003',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ];
    }
}
