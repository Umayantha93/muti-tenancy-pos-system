<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Part;
use App\Models\PartSerial;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SerialInventoryTest extends TestCase
{
    public function test_mobile_shop_keeps_serial_inventory_off_until_enabled(): void
    {
        [$tenant, $owner] = $this->mobileShopOwner();
        Sanctum::actingAs($owner);

        $this->assertFalse(
            $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key')->contains('serial_inventory')
        );
        $this->getJson('/api/serials')->assertForbidden();
    }

    public function test_serialized_phone_is_sold_and_looked_up_by_imei(): void
    {
        [$tenant, $owner] = $this->mobileShopOwner();
        $this->enableOptionalModule($tenant, 'serial_inventory');
        Sanctum::actingAs($owner->fresh());

        $part = Part::create([
            'name' => 'Samsung A15',
            'brand' => 'Samsung',
            'type' => 'Phone',
            'barcode' => '8806095300001',
            'price' => 45000,
            'cost_price' => 38000,
            'stock_qty' => 0,
            'serialized' => true,
        ]);

        $imei = '356938035643809';
        $this->postJson("/api/parts/{$part->id}/serials", [
            'serials' => [$imei],
        ])->assertCreated()
            ->assertJsonPath('created', 1);

        $this->assertSame(1, $part->fresh()->stock_qty);
        $this->assertTrue($part->fresh()->serialized);

        $this->postJson('/api/part-sales', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'items' => [['part_id' => $part->id, 'quantity' => 1]],
            'payment_amount' => 45000,
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $sale = $this->postJson('/api/part-sales', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'items' => [['part_id' => $part->id, 'quantity' => 1, 'serials' => [$imei], 'warranty_months' => 12]],
            'payment_amount' => 45000,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertEquals(0, $part->fresh()->stock_qty);
        $this->assertSame(PartSerial::SOLD, PartSerial::query()->where('serial', $imei)->value('status'));

        $this->getJson('/api/serials/lookup?q='.$imei)->assertOk()
            ->assertJsonPath('serial', $imei)
            ->assertJsonPath('status', 'sold')
            ->assertJsonPath('part.name', 'Samsung A15');

        $this->getJson('/api/warranties?search='.$imei)->assertOk()
            ->assertJsonPath('data.0.part.name', 'Samsung A15');

        $this->assertNotEmpty($sale->json('bill.items.0.warranty_until'));
    }

    public function test_duplicate_imei_is_rejected(): void
    {
        [$tenant, $owner] = $this->mobileShopOwner();
        $this->enableOptionalModule($tenant, 'serial_inventory');
        Sanctum::actingAs($owner->fresh());

        $part = Part::create([
            'name' => 'Redmi 13',
            'brand' => 'Xiaomi',
            'type' => 'Phone',
            'price' => 32000,
            'cost_price' => 27000,
            'stock_qty' => 0,
            'serialized' => true,
        ]);

        $this->postJson("/api/parts/{$part->id}/serials", [
            'serials' => ['490154203237518'],
        ])->assertCreated();

        $this->postJson("/api/parts/{$part->id}/serials", [
            'serials' => ['490154203237518'],
        ])->assertStatus(422);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function mobileShopOwner(): array
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $tenantId = $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Galle Mobile '.fake()->unique()->numerify('###'),
            'business_type' => BusinessTypes::MOBILE_SHOP,
            'owner_name' => 'Shop Owner',
            'owner_phone' => '0771002003',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ])->assertCreated()->json('id');

        $owner = User::query()->where('tenant_id', $tenantId)->where('role', 'business_owner')->firstOrFail();

        return [$owner->tenant, $owner];
    }

    private function enableOptionalModule(Tenant $tenant, string $key): void
    {
        $feature = Feature::query()->where('key', $key)->firstOrFail();
        $tenant->features()->syncWithoutDetaching([$feature->id => ['is_enabled' => true]]);
    }
}
