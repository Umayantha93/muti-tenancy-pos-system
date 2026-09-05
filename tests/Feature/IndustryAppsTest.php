<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IndustryAppsTest extends TestCase
{

    public function test_photography_booking_creates_order_bill_with_package_line(): void
    {
        [, $owner] = $this->tenantWithUser(BusinessTypes::PHOTOGRAPHY, 'business_owner', BusinessTypes::defaults(BusinessTypes::PHOTOGRAPHY));
        Sanctum::actingAs($owner);

        $packageId = $this->postJson('/api/photo-packages', [
            'name' => 'Wedding half day',
            'price' => 45000,
            'duration_minutes' => 240,
        ])->assertCreated()->json('id');

        $booking = $this->postJson('/api/photo-bookings', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0775556677',
            'photo_package_id' => $packageId,
            'scheduled_at' => now()->addDays(3)->toISOString(),
            'create_bill' => true,
        ])->assertCreated()
            ->assertJsonPath('customer.name', 'Nimal Perera')
            ->assertJsonPath('package.name', 'Wedding half day');

        $this->assertNotNull($booking->json('bill_id') ?? $booking->json('bill.id'));
        $billId = $booking->json('bill.id') ?? $booking->json('bill_id');
        $this->getJson("/api/bills/{$billId}")
            ->assertOk()
            ->assertJsonPath('items.0.description', 'Wedding half day')
            ->assertJsonPath('vehicle', null);
    }

    public function test_clothing_sale_decrements_stock_and_creates_sale_bill(): void
    {
        [, $owner] = $this->tenantWithUser(BusinessTypes::CLOTHING, 'business_owner', BusinessTypes::defaults(BusinessTypes::CLOTHING));
        Sanctum::actingAs($owner);

        $productId = $this->postJson('/api/products', [
            'name' => 'Linen Shirt',
            'sku' => 'SHIRT-M-BLU',
            'size' => 'M',
            'color' => 'Blue',
            'price' => 3500,
            'stock_qty' => 10,
        ])->assertCreated()->json('id');

        $sale = $this->postJson('/api/retail-sales', [
            'customer_name' => 'Walk Guest',
            'customer_phone' => '0771112233',
            'items' => [['product_id' => $productId, 'quantity' => 2]],
            'payment_amount' => 7000,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame('SALE-', substr($sale->json('bill.bill_number'), 0, 5));
        $this->getJson("/api/products/{$productId}")->assertOk()->assertJsonPath('stock_qty', 8);
    }

    public function test_cottage_stay_blocks_overlapping_dates_and_creates_stay_bill(): void
    {
        [, $owner] = $this->tenantWithUser(BusinessTypes::COTTAGE, 'business_owner', BusinessTypes::defaults(BusinessTypes::COTTAGE));
        Sanctum::actingAs($owner);

        $roomId = $this->postJson('/api/cottage-rooms', [
            'name' => 'Garden Suite',
            'capacity' => 4,
            'nightly_rate' => 12000,
        ])->assertCreated()->json('id');

        $this->postJson('/api/cottage-stays', [
            'customer_name' => 'Family Silva',
            'customer_phone' => '0779990011',
            'cottage_room_id' => $roomId,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-04',
            'guests' => 3,
            'create_bill' => true,
        ])->assertCreated()->assertJsonPath('bill.subtotal', '36000.00');

        $this->postJson('/api/cottage-stays', [
            'customer_name' => 'Other Guest',
            'customer_phone' => '0779990022',
            'cottage_room_id' => $roomId,
            'check_in' => '2026-09-02',
            'check_out' => '2026-09-05',
            'guests' => 2,
        ])->assertStatus(422);
    }

    public function test_super_admin_onboard_accepts_multiple_phones_and_type_shaped_plan(): void
    {
        $this->seedFeatures();
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Studio North',
            'business_type' => 'photography',
            'owner_name' => 'Photo Owner',
            'owner_phone' => '0771002003',
            'owner_phones' => [
                ['label' => 'Primary', 'number' => '0771002003'],
                ['label' => 'WhatsApp', 'number' => '0771002004'],
            ],
            'contact_phones' => [
                ['label' => 'Front desk', 'number' => '0112345678'],
                ['label' => 'Studio', 'number' => '0112345679'],
            ],
            'owner_email' => 'photo@studio.test',
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ])->assertCreated();

        $this->assertCount(2, $response->json('owner_phones'));
        $this->assertCount(2, $response->json('contact_phones'));
        $keys = collect($response->json('features'))->pluck('key');
        $this->assertTrue($keys->contains('photo_bookings'));
        $this->assertFalse($keys->contains('admit_vehicle'));

        $this->getJson('/api/super-admin/feature-catalog?business_type=clothing')
            ->assertOk()
            ->assertJsonFragment(['key' => 'retail_pos']);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithUser(string $type, string $role, array $enabled): array
    {
        $this->seedFeatures();
        $tenant = Tenant::create([
            'business_name' => fake()->company(),
            'business_type' => $type,
            'owner_name' => fake()->name(),
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $features = Feature::whereIn('key', $enabled)->get();
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);

        return [$tenant, $user];
    }

    private function seedFeatures(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
    }
}
