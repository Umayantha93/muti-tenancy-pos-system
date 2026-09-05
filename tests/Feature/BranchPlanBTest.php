<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchPlanBTest extends TestCase
{

    public function test_new_tenant_gets_a_main_branch_and_stock_stays_on_it(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $this->assertSame(1, Branch::query()->count());
        $this->assertSame('Main', Branch::query()->first()->name);

        $part = Part::create([
            'name' => 'Charger', 'brand' => 'Baseus', 'type' => 'power',
            'price' => 2900, 'cost_price' => 1800, 'stock_qty' => 10,
        ]);

        $this->assertSame(10, BranchStock::query()->where('part_id', $part->id)->value('qty'));
        $this->getJson('/api/parts/'.$part->id)
            ->assertOk()
            ->assertJsonPath('stock_qty', 10);
    }

    public function test_super_admin_can_add_a_branch_and_sales_do_not_share_stock(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null, 'status' => 'active']);
        $owner = $this->owner();
        $tenant = $owner->tenant;
        Sanctum::actingAs($owner);
        $part = Part::create([
            'name' => 'Cable', 'brand' => 'Anker', 'type' => 'cable',
            'price' => 1500, 'cost_price' => 700, 'stock_qty' => 8,
        ]);
        $main = Branch::query()->where('tenant_id', $tenant->id)->first();

        Sanctum::actingAs($admin);
        $kandyId = $this->postJson("/api/super-admin/tenants/{$tenant->id}/branches", [
            'name' => 'Kandy',
            'address' => 'Kandy town',
        ])->assertCreated()->json('id');

        $this->assertSame(0, (int) BranchStock::query()->where('branch_id', $kandyId)->where('part_id', $part->id)->value('qty'));

        $owner->update(['last_branch_id' => $kandyId]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Branch-Id', (string) $kandyId)
            ->postJson('/api/part-sales', [
                'items' => [['part_id' => $part->id, 'quantity' => 1]],
            ])->assertStatus(422);

        $this->withHeader('X-Branch-Id', (string) $main->id)
            ->postJson('/api/part-sales', [
                'items' => [['part_id' => $part->id, 'quantity' => 2]],
            ])->assertCreated();

        $this->assertSame(6, (int) BranchStock::query()->where('branch_id', $main->id)->where('part_id', $part->id)->value('qty'));
        $this->assertSame(0, (int) BranchStock::query()->where('branch_id', $kandyId)->where('part_id', $part->id)->value('qty'));
        $this->assertSame(6, $part->fresh()->stock_qty);
        $this->assertSame($main->id, Bill::query()->latest('id')->first()->branch_id);
    }

    public function test_owner_can_rename_and_staff_cannot_see_other_shop_bills(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $main = Branch::query()->where('tenant_id', $owner->tenant_id)->first();

        $admin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null, 'status' => 'active']);
        Sanctum::actingAs($admin);
        $kandy = $this->postJson("/api/super-admin/tenants/{$owner->tenant_id}/branches", ['name' => 'Kandy'])->assertCreated();
        $kandyId = $kandy->json('id');

        Sanctum::actingAs($owner);
        $this->putJson('/api/branches/'.$kandyId, ['name' => 'Kandy City'])->assertOk()->assertJsonPath('name', 'Kandy City');

        $this->withHeader('X-Branch-Id', (string) $main->id)
            ->postJson('/api/bills/instant', ['customer_name' => 'Nimal'])
            ->assertCreated();
        $mainBillId = Bill::query()->where('branch_id', $main->id)->latest('id')->value('id');

        $staff = User::factory()->create([
            'tenant_id' => $owner->tenant_id,
            'role' => 'staff',
            'status' => 'active',
            'home_branch_id' => $kandyId,
        ]);
        $features = Feature::pluck('id');
        $staff->permissions()->sync($features->mapWithKeys(fn ($id) => [$id => ['can_access' => true]]));
        Sanctum::actingAs($staff);
        $this->putJson('/api/branches/'.$kandyId, ['name' => 'Nope'])->assertForbidden();
        $this->withHeader('X-Branch-Id', (string) $main->id)
            ->postJson('/api/bills/instant', ['customer_name' => 'Other shop'])
            ->assertForbidden();
        $this->getJson('/api/bills/'.$mainBillId)->assertNotFound();
        $this->getJson('/api/bills')->assertOk()->assertJsonPath('data', []);
    }

    public function test_owner_can_transfer_stock_between_shops(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $part = Part::create([
            'name' => 'Glass', 'brand' => 'Generic', 'type' => 'screen',
            'price' => 900, 'cost_price' => 400, 'stock_qty' => 10,
        ]);
        $main = Branch::query()->where('tenant_id', $owner->tenant_id)->first();
        $admin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null, 'status' => 'active']);
        Sanctum::actingAs($admin);
        $kandyId = $this->postJson("/api/super-admin/tenants/{$owner->tenant_id}/branches", ['name' => 'Kandy'])->json('id');

        Sanctum::actingAs($owner);
        $this->postJson('/api/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $kandyId,
            'part_id' => $part->id,
            'quantity' => 3,
        ])->assertCreated();

        $this->assertSame(7, (int) BranchStock::query()->where('branch_id', $main->id)->where('part_id', $part->id)->value('qty'));
        $this->assertSame(3, (int) BranchStock::query()->where('branch_id', $kandyId)->where('part_id', $part->id)->value('qty'));
    }

    private function owner(): User
    {
        $features = collect(['admit_vehicle', 'customers', 'billing', 'parts_inventory', 'bill_profits', 'balance_sheet', 'reports', 'employees_management'])
            ->map(fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0]));
        $tenant = Tenant::create([
            'business_name' => 'MyDearShop', 'business_type' => 'store', 'owner_name' => 'Owner',
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
        ]);
    }
}
