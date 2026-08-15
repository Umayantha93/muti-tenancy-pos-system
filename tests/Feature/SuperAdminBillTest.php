<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminBillTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_reopen_edit_close_and_delete_tenant_bills(): void
    {
        [$tenant, $owner, $admin] = $this->garageTenantWithAdmin();
        Sanctum::actingAs($owner);

        $part = Part::create([
            'name' => 'Oil Filter', 'brand' => 'Toyota', 'type' => 'filter',
            'model' => 'Corolla', 'year' => 2018, 'price' => 1500, 'cost_price' => 800, 'stock_qty' => 10,
        ]);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1234',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 1,
        ])->assertCreated();

        Bill::findOrFail($billId)->update(['status' => 'closed']);
        $this->assertSame(9, $part->fresh()->stock_qty);

        Sanctum::actingAs($owner);
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor', 'description' => 'Fit filter', 'quantity' => 1, 'unit_price' => 1000,
        ])->assertStatus(422);

        Sanctum::actingAs($admin);
        $this->getJson("/api/super-admin/tenants/{$tenant->id}/bills")
            ->assertOk()
            ->assertJsonPath('data.0.id', $billId)
            ->assertJsonPath('data.0.status', 'closed');

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/bills/{$billId}/reopen")
            ->assertOk()
            ->assertJsonPath('status', 'open');

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/bills/{$billId}/items", [
            'type' => 'labor', 'description' => 'Fit filter', 'quantity' => 1, 'unit_price' => 1000,
        ])->assertCreated();

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/bills/{$billId}", [
            'notes' => 'Reopened by platform admin',
        ])->assertOk()->assertJsonPath('notes', 'Reopened by platform admin');

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/bills/{$billId}/close")
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        $this->deleteJson("/api/super-admin/tenants/{$tenant->id}/bills/{$billId}")
            ->assertOk();

        $this->assertNull(Bill::find($billId));
        $this->assertSame(10, $part->fresh()->stock_qty);
    }

    public function test_tenant_users_cannot_access_platform_invoice_tools(): void
    {
        [$tenant, $owner] = $this->garageTenantWithAdmin();
        Sanctum::actingAs($owner);

        $this->getJson("/api/super-admin/tenants/{$tenant->id}/bills")->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: User, 2: User}
     */
    private function garageTenantWithAdmin(): array
    {
        $features = collect(['admit_vehicle', 'customers', 'billing', 'parts_inventory'])
            ->map(fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0]));
        $tenant = Tenant::create([
            'business_name' => fake()->company(),
            'business_type' => 'garage',
            'owner_name' => fake()->name(),
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'tenant_id' => null,
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        return [$tenant, $owner, $admin];
    }
}
