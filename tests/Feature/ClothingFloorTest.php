<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClothingFloorTest extends TestCase
{
    public function test_half_view_changes_the_screen_totals_and_leaves_stock_stored(): void
    {
        [, $owner] = $this->tenant();
        Sanctum::actingAs($owner);

        $productId = $this->postJson('/api/products', [
            'name' => 'Grey Selvedge Jeans',
            'sku' => 'JEAN-20',
            'size' => 'M',
            'color' => 'Grey',
            'price' => 100,
            'cost_price' => 40,
            'stock_qty' => 20,
        ])->assertCreated()->json('id');

        $full = $this->getJson('/api/clothing/insights')->assertOk();
        $this->assertEquals(2000, $full->json('worth'));
        $this->assertEquals(800, $full->json('income'));
        $this->assertEquals(1200, $full->json('profit'));

        $half = $this->getJson('/api/clothing/insights?half_view=1')->assertOk();
        $this->assertEquals(1000, $half->json('worth'));
        $this->assertEquals(400, $half->json('income'));
        $this->assertEquals(600, $half->json('profit'));

        $this->assertSame(20, Product::query()->find($productId)->stock_qty);
    }

    public function test_half_view_lists_about_half_the_bills(): void
    {
        [, $owner] = $this->tenant();
        Sanctum::actingAs($owner);

        $productId = $this->postJson('/api/products', [
            'name' => 'Oxford Shirt',
            'price' => 1000,
            'cost_price' => 400,
            'stock_qty' => 20,
        ])->assertCreated()->json('id');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/retail-sales', [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ])->assertCreated();
        }

        $all = $this->getJson('/api/bills?per_page=50')->assertOk()->json('total');
        $half = $this->getJson('/api/bills?per_page=50&half_view=1')->assertOk()->json('total');

        $this->assertSame(4, $all);
        $this->assertSame(2, $half);
        $this->assertSame(4, \App\Models\Bill::query()->count());
    }

    public function test_customer_display_is_closed_until_super_admin_enables_it(): void
    {
        [$tenant, $owner] = $this->tenant();
        Sanctum::actingAs($owner);

        $this->putJson('/api/clothing/display', [
            'lines' => [],
        ])->assertForbidden();

        $feature = Feature::query()->where('key', 'customer_display')->firstOrFail();
        $tenant->features()->attach($feature->id, ['is_enabled' => true]);

        $this->putJson('/api/clothing/display', [
            'lines' => [[
                'name' => 'Oxford Shirt',
                'detail' => 'M',
                'quantity' => 1,
                'unit_price' => 4990,
                'line_total' => 4990,
            ]],
            'customer_name' => 'Walk-in Customer',
            'subtotal' => 4990,
            'tax' => 0,
            'total' => 4990,
        ])->assertOk()->assertJsonPath('payload.lines.0.name', 'Oxford Shirt');

        $this->putJson('/api/clothing/display', ['lines' => []])->assertOk();
        $this->getJson('/api/clothing/display')
            ->assertOk()
            ->assertJsonPath('payload.lines', [])
            ->assertJsonPath('business_name', $tenant->business_name);
    }

    public function test_sale_records_discount_vip_and_real_stock(): void
    {
        [, $owner] = $this->tenant();
        Sanctum::actingAs($owner);

        $productId = $this->postJson('/api/products', [
            'name' => 'Linen Shirt',
            'price' => 1000,
            'cost_price' => 400,
            'stock_qty' => 10,
        ])->assertCreated()->json('id');

        $sale = $this->postJson('/api/retail-sales', [
            'customer_name' => 'Sarah Jenkins',
            'customer_phone' => '0775550101',
            'vip' => true,
            'discount' => 100,
            'payment_method' => 'card',
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertTrue((bool) $sale->json('customer.is_vip'));
        $this->assertEquals(100, $sale->json('bill.total_deductions'));
        $this->assertEquals(900, $sale->json('bill.amount_paid'));
        $this->assertEquals(9, Product::query()->find($productId)->stock_qty);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenant(): array
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $tenant = Tenant::create([
            'business_name' => 'Thread Test',
            'business_type' => BusinessTypes::CLOTHING,
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $features = Feature::whereIn('key', BusinessTypes::defaults(BusinessTypes::CLOTHING))->get();
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);

        return [$tenant, $user];
    }
}
