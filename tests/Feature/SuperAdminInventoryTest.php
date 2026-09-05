<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminInventoryTest extends TestCase
{

    public function test_super_admin_can_correct_stock_without_creating_expense(): void
    {
        [$tenant, $owner, $admin] = $this->garageTenantWithAdmin();

        Sanctum::actingAs($owner);
        $part = Part::create([
            'name' => 'Air Filter', 'brand' => 'Denso', 'type' => 'filter',
            'price' => 2400, 'cost_price' => 2100, 'stock_qty' => 10,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/super-admin/tenants/{$tenant->id}/inventory/parts")
            ->assertOk()
            ->assertJsonPath('data.0.id', $part->id)
            ->assertJsonPath('data.0.stock_qty', 10);

        $expenseCount = Expense::query()->count();

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/inventory/parts/{$part->id}", [
            'stock_qty' => 7,
            'cost_price' => 2100,
            'price' => 2400,
            'name' => 'Air Filter',
            'brand' => 'Denso',
            'note' => 'Physical count correction',
        ])
            ->assertOk()
            ->assertJsonPath('stock_qty', 7)
            ->assertJsonPath('cost_price', '2100.00');

        $this->assertSame($expenseCount, Expense::query()->count());
        $this->assertSame(7, $part->fresh()->stock_qty);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'inventory.part_corrected',
            'subject_id' => $part->id,
        ]);
        $this->assertSame('Physical count correction', AuditLog::query()->latest('id')->first()->metadata['note'] ?? null);
    }

    public function test_tenant_owner_cannot_use_platform_inventory_tools(): void
    {
        [$tenant, $owner] = $this->garageTenantWithAdmin();
        Sanctum::actingAs($owner);

        $this->getJson("/api/super-admin/tenants/{$tenant->id}/inventory/parts")->assertForbidden();
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
