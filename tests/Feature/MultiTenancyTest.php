<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_queries_and_route_binding_never_expose_another_tenants_data(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithUser('business_owner', ['parts_inventory']);
        Sanctum::actingAs($ownerA);
        $partA = Part::create(['name' => 'Tenant A Part', 'brand' => 'A', 'type' => 'brake', 'price' => 100, 'stock_qty' => 2]);

        [, $ownerB] = $this->tenantWithUser('business_owner', ['parts_inventory']);
        Sanctum::actingAs($ownerB);
        $partB = Part::create(['name' => 'Tenant B Part', 'brand' => 'B', 'type' => 'engine', 'price' => 200, 'stock_qty' => 3]);

        Sanctum::actingAs($ownerA);
        $this->getJson('/api/parts')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $partA->id)
            ->assertJsonMissing(['id' => $partB->id]);
        $this->getJson("/api/parts/{$partB->id}")->assertNotFound();
        $this->assertSame($tenantA->id, $partA->tenant_id);
    }

    public function test_feature_access_requires_both_tenant_enablement_and_staff_permission(): void
    {
        [$tenant, $staff] = $this->tenantWithUser('staff', ['billing', 'payroll']);
        $payroll = Feature::where('key', 'payroll')->firstOrFail();
        $staff->permissions()->sync([$payroll->id => ['can_access' => false]]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/payroll')->assertForbidden();
        $this->getJson('/api/parts')->assertForbidden();

        $staff->permissions()->sync([$payroll->id => ['can_access' => true]]);
        $this->getJson('/api/payroll')->assertOk();
    }

    public function test_inactive_user_or_tenant_is_blocked_on_every_authenticated_request(): void
    {
        [$tenant, $owner] = $this->tenantWithUser('business_owner', ['billing']);
        Sanctum::actingAs($owner);
        $tenant->update(['status' => 'inactive']);
        $this->getJson('/api/dashboard')->assertForbidden();

        $tenant->update(['status' => 'active']);
        $owner->update(['status' => 'inactive']);
        $this->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_super_admin_can_onboard_and_deactivate_a_tenant_with_an_audit_trail(): void
    {
        $this->seedFeatures();
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $tenantId = $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Colombo Auto Care', 'business_type' => 'garage', 'owner_name' => 'A. Owner',
            'owner_phone' => '0771112233', 'owner_email' => 'owner@colombo.test', 'password' => 'password123',
            'features' => ['admit_vehicle', 'billing', 'parts_inventory'],
        ])->assertCreated()->assertJsonCount(1, 'users')->json('id');

        $this->assertDatabaseHas('users', ['tenant_id' => $tenantId, 'email' => 'owner@colombo.test', 'role' => 'business_owner']);
        $this->postJson("/api/super-admin/tenants/{$tenantId}/deactivate")->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.created', 'tenant_id' => $tenantId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.inactive', 'tenant_id' => $tenantId]);
    }

    private function tenantWithUser(string $role, array $enabled): array
    {
        $this->seedFeatures();
        $tenant = Tenant::create(['business_name' => fake()->company(), 'business_type' => 'garage', 'owner_name' => fake()->name(),
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active']);
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
