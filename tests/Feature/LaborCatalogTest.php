<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Feature;
use App\Models\LaborCategory;
use App\Models\LaborItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LaborCatalogDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaborCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_garage_catalog_seeds_default_categories_and_items(): void
    {
        Sanctum::actingAs($this->garageUser());

        $catalog = $this->getJson('/api/labor-catalog')->assertOk()->json();

        $this->assertCount(13, $catalog);
        $this->assertSame('Brakes', $catalog[0]['name']);
        $this->assertSame('Front brake pads replacement', $catalog[0]['items'][0]['name']);
        $this->assertSame('3000.00', $catalog[0]['items'][0]['hourly_rate']);
        $this->assertSame('1.00', $catalog[0]['items'][0]['standard_hours']);
        $this->assertSame('3000.00', $catalog[0]['items'][0]['standard_price']);

        $expectedCount = collect(LaborCatalogDefaults::catalog())->sum(fn ($category) => count($category['items']));
        $this->assertSame($expectedCount, collect($catalog)->sum(fn ($category) => count($category['items'])));
    }

    public function test_owner_can_add_update_and_delete_categories_and_items(): void
    {
        Sanctum::actingAs($this->garageUser());
        $this->getJson('/api/labor-catalog')->assertOk();

        $category = $this->postJson('/api/labor-categories', ['name' => 'Diagnostics'])
            ->assertCreated()
            ->json();

        $this->putJson("/api/labor-categories/{$category['id']}", ['name' => 'Advanced diagnostics'])
            ->assertOk()
            ->assertJsonPath('name', 'Advanced diagnostics');

        $item = $this->postJson("/api/labor-categories/{$category['id']}/items", [
            'name' => 'Scan & report',
            'hourly_rate' => 2500,
            'standard_hours' => 0.5,
        ])->assertCreated()->json();

        $this->assertSame('1250.00', $item['standard_price']);

        $this->putJson("/api/labor-items/{$item['id']}", [
            'hourly_rate' => 4000,
            'standard_hours' => 0.75,
        ])->assertOk()->assertJsonPath('standard_price', '3000.00');

        $this->deleteJson("/api/labor-items/{$item['id']}")->assertNoContent();
        $this->deleteJson("/api/labor-categories/{$category['id']}")->assertNoContent();
        $this->assertNull(LaborCategory::query()->find($category['id']));
    }

    public function test_staff_cannot_mutate_the_catalog(): void
    {
        $owner = $this->garageUser();
        Sanctum::actingAs($owner);
        $this->getJson('/api/labor-catalog')->assertOk();
        $categoryId = LaborCategory::query()->value('id');

        $staff = User::factory()->create([
            'tenant_id' => $owner->tenant_id,
            'role' => 'staff',
            'status' => 'active',
        ]);
        $staff->permissions()->sync(
            $owner->tenant->features()->pluck('features.id')->mapWithKeys(fn ($id) => [$id => ['can_access' => true]])
        );
        Sanctum::actingAs($staff);

        $this->getJson('/api/labor-catalog')->assertOk();
        $this->postJson('/api/labor-categories', ['name' => 'Nope'])->assertForbidden();
        $this->postJson("/api/labor-categories/{$categoryId}/items", [
            'name' => 'Nope',
            'hourly_rate' => 1000,
            'standard_hours' => 1,
        ])->assertForbidden();
    }

    public function test_bill_labor_hours_change_the_amount_and_shared_bill_hides_hours(): void
    {
        Sanctum::actingAs($this->garageUser());
        $this->getJson('/api/labor-catalog')->assertOk();

        $labor = LaborItem::query()->where('name', 'Front brake pads replacement')->firstOrFail();

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1234',
        ])->assertCreated()->json('id');

        $created = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'labor_item_id' => $labor->id,
            'quantity' => 2,
        ])->assertCreated()->json();

        $this->assertSame('Front brake pads replacement', $created['item']['description']);
        $this->assertSame('3000.00', $created['item']['unit_price']);
        $this->assertSame('2.00', $created['item']['quantity']);
        $this->assertSame('6000.00', $created['item']['line_total']);

        $updated = $this->putJson("/api/bills/{$billId}/items/{$created['item']['id']}", [
            'quantity' => 1.5,
        ])->assertOk()->json();

        $this->assertSame('1.50', $updated['item']['quantity']);
        $this->assertSame('4500.00', $updated['item']['line_total']);

        $token = Bill::query()->findOrFail($billId)->share_token;
        $shared = $this->getJson('/api/bills/shared/'.$token)->assertOk()->json();
        $line = collect($shared['items'])->firstWhere('type', 'labor');

        $this->assertTrue($line['hide_hours']);
        $this->assertNull($line['quantity']);
        $this->assertNull($line['unit_price']);
        $this->assertSame('4500.00', $line['line_total']);
        $this->assertSame('Front brake pads replacement', $line['description']);
        $this->assertArrayNotHasKey('hourly_rate', $line);
    }

    public function test_deleting_a_catalog_item_keeps_existing_bill_description(): void
    {
        Sanctum::actingAs($this->garageUser());
        $this->getJson('/api/labor-catalog')->assertOk();
        $labor = LaborItem::query()->where('name', 'Engine oil & filter change')->firstOrFail();

        $billId = $this->postJson('/api/bills', [
            'number_plate' => 'CAB-5555',
        ])->assertCreated()->json('id');

        $itemId = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'labor_item_id' => $labor->id,
        ])->assertCreated()->json('item.id');

        $this->deleteJson("/api/labor-items/{$labor->id}")->assertNoContent();

        $billItem = BillItem::query()->findOrFail($itemId);
        $this->assertNull($billItem->labor_item_id);
        $this->assertSame('Engine oil & filter change', $billItem->description);
        $this->assertSame('0.50', $billItem->quantity);
        $this->assertSame('1500.00', $billItem->line_total);
    }

    private function garageUser(): User
    {
        $features = collect([
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports',
        ])->map(fn (string $key) => Feature::firstOrCreate(
            ['key' => $key],
            ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0],
        ));
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
