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

    public function test_half_on_keeps_bill_numbers_in_order_and_blocks_changes(): void
    {
        [$tenant, $owner] = $this->tenant();
        Sanctum::actingAs($owner);

        $this->post('/api/tenant/profile', [
            'bill_prefix' => 'CL',
            'lock_bill_numbers' => '1',
        ])->assertOk();

        $productId = $this->postJson('/api/products', [
            'name' => 'Oxford Shirt',
            'price' => 1000,
            'cost_price' => 400,
            'stock_qty' => 20,
        ])->assertCreated()->json('id');

        $numbers = [];
        for ($i = 0; $i < 4; $i++) {
            $numbers[] = $this->postJson('/api/retail-sales', [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ])->assertCreated()->json('bill.bill_number');
        }

        $shop = str_pad((string) $tenant->id, 2, '0', STR_PAD_LEFT);
        $this->assertSame(["CL-{$shop}-0001", "CL-{$shop}-0002", "CL-{$shop}-0003", "CL-{$shop}-0004"], $numbers);

        $this->post('/api/tenant/profile', ['half_view' => '1'])->assertOk();
        $this->post('/api/tenant/profile', [
            'bill_prefix' => 'NEW',
            'lock_bill_numbers' => '1',
        ])->assertStatus(422);

        $listed = collect($this->getJson('/api/bills?per_page=50')->assertOk()->json('data'))->pluck('bill_number')->sort()->values()->all();
        $this->assertSame($numbers, $listed);
        $tenant->refresh();
        $this->assertTrue((bool) $tenant->half_view);
        $this->assertSame('CL', $tenant->bill_prefix);
        $this->assertSame(4, (int) $tenant->bill_sequence);
    }

    public function test_catalog_item_can_take_an_image_category_and_subcategory(): void
    {
        [, $owner] = $this->tenant();
        Sanctum::actingAs($owner);

        $categoryId = $this->postJson('/api/product-categories', ['name' => 'Men'])->assertCreated()->json('id');
        $this->postJson("/api/product-categories/{$categoryId}/subcategories", ['name' => 'Shirts'])->assertCreated();

        $product = $this->post('/api/products', [
            'name' => 'Oxford Shirt',
            'category' => 'Men',
            'subcategory' => 'Shirts',
            'price' => 4500,
            'stock_qty' => 2,
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('oxford.jpg')],
        ])->assertCreated();

        $this->postJson('/api/products', [
            'name' => 'Grey Selvedge Jeans',
            'price' => 100,
            'size_scale' => 'jeans',
            'size_quantities' => ['34' => 5, '36' => 2],
        ])->assertCreated();
        $this->postJson('/api/products', [
            'name' => 'Oxford Shirt',
            'price' => 80,
            'size_scale' => 'apparel',
            'size_quantities' => ['S' => 4, 'M' => 7, 'L' => 1, 'XL' => 3],
        ])->assertCreated();

        $this->assertSame(8, Product::query()->where('name', 'Grey Selvedge Jeans')->count());
        $this->assertSame(5, (int) Product::query()->where('name', 'Grey Selvedge Jeans')->where('size', '34')->value('stock_qty'));
        $this->assertSame(0, (int) Product::query()->where('name', 'Grey Selvedge Jeans')->where('size', '28')->value('stock_qty'));

        $stock = $this->getJson('/api/products/size-stock')->assertOk();
        $this->assertSame(5, collect($stock->json('jeans'))->firstWhere('size', '34')['qty']);
        $this->assertSame(2, collect($stock->json('jeans'))->firstWhere('size', '36')['qty']);
        $this->assertSame(7, collect($stock->json('apparel'))->firstWhere('size', 'M')['qty']);
        $this->assertSame(3, collect($stock->json('apparel'))->firstWhere('size', 'XL')['qty']);

        $this->assertSame('Men', $product->json('category'));
        $this->assertSame('Shirts', $product->json('subcategory'));
        $this->assertNotEmpty($product->json('image_urls'));
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

    public function test_cash_tender_above_the_total_returns_the_balance_and_records_only_the_due(): void
    {
        [, $owner] = $this->tenant();
        Sanctum::actingAs($owner);

        $productId = $this->postJson('/api/products', [
            'name' => 'Camp Shirt',
            'price' => 22492.05,
            'cost_price' => 8000,
            'stock_qty' => 4,
        ])->assertCreated()->json('id');

        $sale = $this->postJson('/api/retail-sales', [
            'payment_method' => 'cash',
            'cash_tendered' => 23000,
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertCreated();

        $paid = round((float) $sale->json('bill.amount_paid'), 2);
        $this->assertEquals(0, round((float) $sale->json('bill.balance_due'), 2));
        $this->assertEqualsWithDelta(22492.05, $paid, 0.02);
        $this->assertEqualsWithDelta(507.95, (float) $sale->json('change'), 0.02);
        $this->assertEquals(3, Product::query()->find($productId)->stock_qty);

        $larger = $this->postJson('/api/retail-sales', [
            'payment_method' => 'cash',
            'cash_tendered' => 25000,
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertCreated();

        $paidAgain = round((float) $larger->json('bill.amount_paid'), 2);
        $this->assertEqualsWithDelta(22492.05, $paidAgain, 0.02);
        $this->assertEqualsWithDelta(2507.95, (float) $larger->json('change'), 0.02);

        $this->postJson('/api/retail-sales', [
            'payment_method' => 'cash',
            'cash_tendered' => 20000,
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertEquals(2, Product::query()->find($productId)->stock_qty);
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
