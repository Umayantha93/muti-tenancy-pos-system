<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\TenantFeePayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantFeePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_due_soon_is_false_when_current_month_is_marked_paid(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 28, 12, 0, 0));

        [$tenant, $owner] = $this->monthlyTenantWithOwner();
        Sanctum::actingAs($owner);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.tenant.payment_due_soon', true);

        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/fee-payments/2026/8", ['paid' => true])
            ->assertOk()
            ->assertJsonPath('current_month_paid', true)
            ->assertJsonCount(1, 'payments');

        Sanctum::actingAs($owner);
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.tenant.payment_due_soon', false);

        Sanctum::actingAs($admin);
        $this->putJson("/api/super-admin/tenants/{$tenant->id}/fee-payments/2026/8", ['paid' => false])
            ->assertOk()
            ->assertJsonPath('current_month_paid', false)
            ->assertJsonCount(0, 'payments');

        Sanctum::actingAs($owner);
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.tenant.payment_due_soon', true);

        Carbon::setTestNow();
    }

    public function test_tenant_list_includes_current_month_paid_flag(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        [$tenant] = $this->monthlyTenantWithOwner();
        TenantFeePayment::create([
            'tenant_id' => $tenant->id,
            'year' => 2026,
            'month' => 8,
            'amount' => 15000,
            'paid_at' => now(),
            'marked_by' => null,
        ]);

        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/super-admin/tenants')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tenant->id)
            ->assertJsonPath('data.0.current_month_paid', true);

        Carbon::setTestNow();
    }

    public function test_fee_payment_history_lists_past_months_newest_first(): void
    {
        [$tenant] = $this->monthlyTenantWithOwner();
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);

        TenantFeePayment::create([
            'tenant_id' => $tenant->id,
            'year' => 2026,
            'month' => 6,
            'amount' => 15000,
            'paid_at' => now()->subMonths(2),
            'marked_by' => $admin->id,
        ]);
        TenantFeePayment::create([
            'tenant_id' => $tenant->id,
            'year' => 2026,
            'month' => 7,
            'amount' => 15000,
            'paid_at' => now()->subMonth(),
            'marked_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/super-admin/tenants/{$tenant->id}/fee-payments")
            ->assertOk()
            ->assertJsonPath('payments.0.period', '2026-07')
            ->assertJsonPath('payments.1.period', '2026-06')
            ->assertJsonPath('payments.0.marked_by.id', $admin->id);

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/fee-payments/2026/8", ['paid' => true])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.fee_marked_paid',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_yearly_tenants_cannot_be_marked_for_monthly_fee_payments(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $tenant = Tenant::create([
            'business_name' => 'Yearly Studio',
            'business_type' => 'photography',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => 'yearly@test.com',
            'status' => 'active',
            'payment_plan' => 'yearly',
            'plan_amount' => 120000,
        ]);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/super-admin/tenants/{$tenant->id}/fee-payments/2026/8", ['paid' => true])
            ->assertStatus(422);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function monthlyTenantWithOwner(): array
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $tenant = Tenant::create([
            'business_name' => 'Bay Fee Garage',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ]);
        $features = Feature::whereIn('key', ['billing'])->get();
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
        ]);

        return [$tenant, $owner];
    }
}
