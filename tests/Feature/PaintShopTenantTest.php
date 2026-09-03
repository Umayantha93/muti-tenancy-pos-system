<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\LaborCategory;
use App\Models\LaborItem;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Models\User;
use App\Support\BusinessTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaintShopTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_onboard_seeds_paint_catalogs_not_garage_defaults(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/super-admin/feature-catalog?business_type=paint')
            ->assertOk()
            ->assertJsonFragment(['key' => 'admit_vehicle']);

        $paint = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            businessName: 'Booth Colour Works',
            businessType: BusinessTypes::PAINT,
            ownerEmail: 'paint@shop.test',
        ))->assertCreated()
            ->assertJsonPath('business_type', 'paint')
            ->assertJsonPath('plan', 'paint-pro');

        $paintId = $paint->json('id');
        $featureKeys = collect($paint->json('features'))->pluck('key');
        $this->assertTrue($featureKeys->contains('admit_vehicle'));
        $this->assertTrue($featureKeys->contains('parts_inventory'));
        $this->assertTrue($featureKeys->contains('billing'));

        $laborNames = LaborCategory::withoutGlobalScopes()
            ->where('tenant_id', $paintId)
            ->orderBy('sort_order')
            ->pluck('name');
        $this->assertSame(['Prep', 'Paint', 'Finish'], $laborNames->all());
        $this->assertFalse($laborNames->contains('Brakes'));
        $this->assertFalse($laborNames->contains('Bumpers'));

        $addonNames = ServiceAddon::withoutGlobalScopes()->where('tenant_id', $paintId)->pluck('name');
        $this->assertTrue($addonNames->contains('Bumper respray'));
        $this->assertFalse($addonNames->contains('Oil and filter change'));
        $this->assertFalse($addonNames->contains('Full service'));

        $this->assertTrue(
            Part::withoutGlobalScopes()->where('tenant_id', $paintId)->where('name', '2K primer grey')->exists()
        );

        $garageId = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            businessName: 'Colombo Auto Care',
            businessType: BusinessTypes::GARAGE,
            ownerEmail: 'garage@shop.test',
        ))->assertCreated()->json('id');

        $garageLabor = LaborCategory::withoutGlobalScopes()
            ->where('tenant_id', $garageId)
            ->orderBy('sort_order')
            ->value('name');
        $this->assertSame('Brakes', $garageLabor);
        $this->assertFalse(
            LaborCategory::withoutGlobalScopes()->where('tenant_id', $garageId)->where('name', 'Prep')->exists()
        );
        $this->assertTrue(
            ServiceAddon::withoutGlobalScopes()->where('tenant_id', $garageId)->where('name', 'Oil and filter change')->exists()
        );
        $this->assertFalse(
            ServiceAddon::withoutGlobalScopes()->where('tenant_id', $garageId)->where('name', 'Bumper respray')->exists()
        );
        $this->assertFalse(
            Part::withoutGlobalScopes()->where('tenant_id', $garageId)->where('name', '2K primer grey')->exists()
        );
    }

    public function test_paint_owner_can_bill_labor_hours_and_millilitre_stock_and_shared_bill_hides_hours(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $tenantId = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            businessName: 'Island Paint',
            businessType: BusinessTypes::PAINT,
            ownerEmail: 'island-paint@shop.test',
        ))->assertCreated()->json('id');

        $owner = User::query()->where('tenant_id', $tenantId)->where('role', 'business_owner')->firstOrFail();
        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('business_type', 'paint')
            ->assertJsonPath('low_stock_parts', 0);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAA-4521',
            'job_kind' => 'repair',
        ])->assertCreated()->json('id');
        $billNumber = Bill::query()->findOrFail($billId)->bill_number;
        $this->assertSame('JOB-', substr($billNumber, 0, 4));
        $masking = LaborItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', 'Masking')
            ->firstOrFail();

        $labor = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'labor_item_id' => $masking->id,
            'quantity' => 3.5,
        ])->assertCreated()->json();

        $this->assertSame('Masking', $labor['item']['description']);
        $this->assertSame('3000.00', $labor['item']['unit_price']);
        $this->assertSame('3.50', $labor['item']['quantity']);
        $this->assertSame('10500.00', $labor['item']['line_total']);

        $primer = Part::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', '2K primer grey')
            ->firstOrFail();
        $this->assertEquals(3200, $primer->stock_qty);

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part',
            'part_id' => $primer->id,
            'quantity' => 180,
        ])->assertCreated();
        $this->assertEquals(3020, $primer->fresh()->stock_qty);

        $token = Bill::withoutGlobalScopes()->findOrFail($billId)->share_token;
        $shared = $this->getJson('/api/bills/shared/'.$token)->assertOk()->json();
        $line = collect($shared['items'])->firstWhere('type', 'labor');

        $this->assertTrue($line['hide_hours']);
        $this->assertNull($line['quantity']);
        $this->assertNull($line['unit_price']);
        $this->assertSame('10500.00', $line['line_total']);
        $this->assertSame('Masking', $line['description']);
        $this->assertArrayNotHasKey('hourly_rate', $line);
    }

    public function test_paint_panel_composer_groups_labor_and_stock_and_share_shows_panel_total_only(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $tenantId = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            businessName: 'Kandy Colour',
            businessType: BusinessTypes::PAINT,
            ownerEmail: 'kandy-paint@shop.test',
        ))->assertCreated()->json('id');

        $owner = User::query()->where('tenant_id', $tenantId)->where('role', 'business_owner')->firstOrFail();
        Sanctum::actingAs($owner);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAA-4521',
            'job_kind' => 'repair',
        ])->assertCreated()->json('id');

        $masking = LaborItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', 'Masking')
            ->firstOrFail();
        $primer = Part::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', '2K primer grey')
            ->firstOrFail();
        $this->assertEquals(3200, $primer->stock_qty);

        $created = $this->postJson("/api/bills/{$billId}/items/panel", [
            'panel_name' => 'Front bumper',
            'labor' => [
                ['labor_item_id' => $masking->id, 'quantity' => 3.5],
            ],
            'materials' => [
                ['part_id' => $primer->id, 'quantity' => 180],
            ],
        ])->assertCreated()->json();

        $this->assertCount(2, $created['items']);
        $this->assertSame('Front bumper', $created['items'][0]['panel_name']);
        $this->assertSame($created['items'][0]['panel_group_id'], $created['items'][1]['panel_group_id']);
        $this->assertSame('10500.00', collect($created['items'])->firstWhere('type', 'labor')['line_total']);
        $this->assertSame('3960.00', collect($created['items'])->firstWhere('type', 'part')['line_total']);
        $this->assertEquals(3020, $primer->fresh()->stock_qty);

        $token = Bill::withoutGlobalScopes()->findOrFail($billId)->share_token;
        $shared = $this->getJson('/api/bills/shared/'.$token)->assertOk()->json();
        $this->assertCount(1, $shared['items']);
        $line = $shared['items'][0];
        $this->assertSame('Front bumper', $line['description']);
        $this->assertTrue($line['hide_hours']);
        $this->assertNull($line['quantity']);
        $this->assertNull($line['unit_price']);
        $this->assertSame('14460.00', $line['line_total']);
        $this->assertNull(collect($shared['items'])->firstWhere('description', 'Masking'));
        $descriptions = collect($shared['items'])->pluck('description')->implode(' ');
        $this->assertStringNotContainsString('primer', strtolower($descriptions));

        $itemId = $created['items'][0]['id'];
        $this->deleteJson("/api/bills/{$billId}/items/{$itemId}")->assertOk();
        $this->assertEquals(3200, $primer->fresh()->stock_qty);
        $this->assertSame(0, Bill::query()->findOrFail($billId)->items()->count());
    }

    public function test_garage_cannot_post_panel_groups(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $tenantId = $this->postJson('/api/super-admin/tenants', $this->onboardPayload(
            businessName: 'Colombo Auto Care',
            businessType: BusinessTypes::GARAGE,
            ownerEmail: 'garage-panel@shop.test',
        ))->assertCreated()->json('id');

        $owner = User::query()->where('tenant_id', $tenantId)->where('role', 'business_owner')->firstOrFail();
        Sanctum::actingAs($owner);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'number_plate' => 'CAB-1234',
            'job_kind' => 'repair',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items/panel", [
            'panel_name' => 'Front bumper',
            'labor' => [],
            'materials' => [],
        ])->assertStatus(422);
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
