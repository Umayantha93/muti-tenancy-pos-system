<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantFeePayment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminAccountTest extends TestCase
{
    public function test_super_admin_can_update_name_email_and_password(): void
    {
        $admin = User::factory()->create([
            'tenant_id' => null,
            'role' => 'super_admin',
            'status' => 'active',
            'name' => 'Platform Administrator',
            'email' => 'superadmin@example.test',
            'password' => 'password',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/super-admin/me')
            ->assertOk()
            ->assertJsonPath('email', 'superadmin@example.test');

        $this->putJson('/api/super-admin/me', [
            'name' => 'Ops Lead',
            'email' => 'ops@example.test',
            'current_password' => 'password',
            'password' => 'new-secret-9',
            'password_confirmation' => 'new-secret-9',
        ])->assertOk()
            ->assertJsonPath('name', 'Ops Lead')
            ->assertJsonPath('email', 'ops@example.test');

        $this->assertTrue(Hash::check('new-secret-9', $admin->fresh()->password));
    }

    public function test_super_admin_income_sums_marked_fee_payments(): void
    {
        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $tenant = Tenant::create([
            'business_name' => 'Income Garage',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ]);

        TenantFeePayment::create([
            'tenant_id' => $tenant->id,
            'year' => 2026,
            'month' => 8,
            'amount' => 15000,
            'paid_at' => now(),
            'marked_by' => $admin->id,
        ]);
        TenantFeePayment::create([
            'tenant_id' => $tenant->id,
            'year' => 2026,
            'month' => 9,
            'amount' => 15000,
            'paid_at' => now(),
            'marked_by' => $admin->id,
        ]);

        $this->getJson('/api/super-admin/income?year=2026')
            ->assertOk()
            ->assertJsonPath('collected', 30000)
            ->assertJsonPath('payment_count', 2)
            ->assertJsonPath('tenants.0.business_name', 'Income Garage');

        $this->getJson('/api/super-admin/income?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('collected', 15000)
            ->assertJsonPath('payment_count', 1);
    }
}
