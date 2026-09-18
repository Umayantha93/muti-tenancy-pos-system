<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantSetupFeePaymentTest extends TestCase
{
    public function test_super_admin_can_record_one_time_payment_in_installments(): void
    {
        [$tenant, $admin] = $this->tenantWithSetupFee(60000);
        Sanctum::actingAs($admin);

        $this->getJson("/api/super-admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('setup_fee_amount', '60000.00')
            ->assertJsonPath('setup_fee_paid', 0)
            ->assertJsonPath('setup_fee_balance', 60000)
            ->assertJsonPath('setup_fee_settled', false);

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", [
            'amount' => 20000,
            'notes' => 'September',
        ])->assertOk()
            ->assertJsonPath('setup_fee_paid', 20000)
            ->assertJsonPath('setup_fee_balance', 40000)
            ->assertJsonPath('setup_fee_settled', false)
            ->assertJsonCount(1, 'payments');

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", [
            'amount' => 20000,
            'notes' => 'October',
        ])->assertOk()
            ->assertJsonPath('setup_fee_balance', 20000);

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", [
            'amount' => 20000,
            'notes' => 'November settle',
        ])->assertOk()
            ->assertJsonPath('setup_fee_paid', 60000)
            ->assertJsonPath('setup_fee_balance', 0)
            ->assertJsonPath('setup_fee_settled', true)
            ->assertJsonCount(3, 'payments');

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", ['amount' => 1])
            ->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.setup_fee_payment_recorded',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_setup_fee_payment_cannot_exceed_remaining_balance(): void
    {
        [$tenant, $admin] = $this->tenantWithSetupFee(60000);
        Sanctum::actingAs($admin);

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", ['amount' => 20000])
            ->assertOk();
        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", ['amount' => 45000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Settle amount cannot be more than the available balance of LKR 40000.00.');
    }

    public function test_super_admin_can_remove_a_setup_fee_installment(): void
    {
        [$tenant, $admin] = $this->tenantWithSetupFee(60000);
        Sanctum::actingAs($admin);

        $created = $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", ['amount' => 20000])
            ->assertOk();
        $paymentId = $created->json('payments.0.id');

        $this->deleteJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('setup_fee_paid', 0)
            ->assertJsonPath('setup_fee_balance', 60000)
            ->assertJsonCount(0, 'payments');
    }

    public function test_setup_fee_amount_cannot_drop_below_amount_already_received(): void
    {
        [$tenant, $admin] = $this->tenantWithSetupFee(60000);
        Sanctum::actingAs($admin);

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", ['amount' => 20000])
            ->assertOk();

        $this->postJson("/api/super-admin/tenants/{$tenant->id}", ['setup_fee_amount' => 15000])
            ->assertStatus(422);

        $this->postJson("/api/super-admin/tenants/{$tenant->id}", ['setup_fee_amount' => 80000])
            ->assertOk()
            ->assertJsonPath('setup_fee_amount', '80000.00')
            ->assertJsonPath('setup_fee_balance', 60000);
    }

    public function test_onboard_accepts_one_time_payment_amount(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Setup Fee Shop',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
            'setup_fee_amount' => 60000,
        ])->assertCreated()
            ->assertJsonPath('setup_fee_amount', '60000.00')
            ->assertJsonPath('setup_fee_balance', 60000)
            ->assertJsonPath('setup_fee_settled', false);
    }

    public function test_cannot_record_setup_fee_without_an_amount(): void
    {
        $tenant = Tenant::create([
            'business_name' => 'No Setup Fee',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ]);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", ['amount' => 1000])
            ->assertStatus(422);
    }

    public function test_setup_fee_settlements_are_included_in_platform_income(): void
    {
        [$tenant, $admin] = $this->tenantWithSetupFee(60000);
        Sanctum::actingAs($admin);

        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", [
            'amount' => 18500.5,
            'paid_at' => '2028-03-12',
            'notes' => 'First settlement',
        ])->assertOk();
        $this->postJson("/api/super-admin/tenants/{$tenant->id}/setup-fee-payments", [
            'amount' => 7000,
            'paid_at' => '2028-03-28',
        ])->assertOk();

        $month = collect($this->getJson('/api/super-admin/income?year=2028&month=3')->json('payments'))
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'setup_fee');
        $this->assertEquals(25500.5, round((float) $month->sum('amount'), 2));
        $this->assertTrue($month->contains(fn ($row) => (float) $row['amount'] === 18500.5));
        $this->assertTrue($month->contains(fn ($row) => (float) $row['amount'] === 7000.0));

        $year = collect($this->getJson('/api/super-admin/income?year=2028')->json('payments'))
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'setup_fee');
        $this->assertEquals(25500.5, round((float) $year->sum('amount'), 2));
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithSetupFee(float $amount): array
    {
        $tenant = Tenant::create([
            'business_name' => 'Setup Fee Garage',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
            'setup_fee_amount' => $amount,
        ]);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);

        return [$tenant, $admin];
    }
}
