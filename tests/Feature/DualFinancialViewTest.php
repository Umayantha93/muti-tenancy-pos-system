<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DualFinancialViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_sees_real_amounts_and_secondary_sees_scaled_amounts(): void
    {
        [$primary, $secondary, $bill] = $this->seedDualTenantWithBill();

        Sanctum::actingAs($primary);
        $primaryBill = $this->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->json();

        $this->assertSame('10000.00', $primaryBill['subtotal']);
        $this->assertSame('10000.00', $primaryBill['balance_due']);
        $this->assertSame('10000.00', $primaryBill['items'][0]['unit_price']);
        $this->assertArrayNotHasKey('is_secondary_view', $primaryBill);
        $this->assertArrayNotHasKey('dual_financial_view_enabled', $primaryBill);

        Sanctum::actingAs($secondary);
        $secondaryBill = $this->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->json();

        $this->assertSame('6500.00', $secondaryBill['subtotal']);
        $this->assertSame('6500.00', $secondaryBill['balance_due']);
        $this->assertSame('6500.00', $secondaryBill['items'][0]['unit_price']);
        $this->assertSame('6500.00', $secondaryBill['items'][0]['line_total']);
        $this->assertArrayNotHasKey('is_secondary_view', $secondaryBill);
        $this->assertFalse(isset($secondary->fresh()->toArray()['is_secondary_view']));

        $login = $this->postJson('/api/auth/login', [
            'email' => $secondary->email,
            'password' => 'password',
        ])->assertOk()->json();

        $this->assertArrayNotHasKey('is_secondary_view', $login['user']);
        $this->assertArrayNotHasKey('dual_financial_view_enabled', $login['user']['tenant'] ?? []);
    }

    public function test_secondary_cannot_mutate_financial_data(): void
    {
        [, $secondary, $bill] = $this->seedDualTenantWithBill();
        Sanctum::actingAs($secondary);

        $this->postJson('/api/bills', [
            'customer_name' => 'Blocked',
            'customer_phone' => '0779999999',
            'number_plate' => 'XYZ-9999',
            'chassis_number' => 'CHASSISBLOCKED999',
        ])->assertForbidden()->assertJsonPath('message', 'This action is not permitted for your account.');

        $this->postJson("/api/bills/{$bill->id}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertForbidden();

        $this->postJson('/api/expenses', [
            'category' => 'utilities',
            'description' => 'Power',
            'amount' => 500,
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_balance_sheet_and_dashboard_scale_consistently(): void
    {
        [$primary, $secondary] = $this->seedDualTenantWithBill();

        Expense::create([
            'category' => 'utilities',
            'description' => 'Electricity',
            'amount' => 2000,
            'expense_date' => now()->toDateString(),
            'created_by' => $primary->id,
        ]);

        BillPayment::create([
            'bill_id' => Bill::first()->id,
            'amount' => 4000,
            'method' => 'cash',
            'paid_at' => now(),
            'received_by' => $primary->id,
        ]);
        Bill::first()->update([
            'amount_paid' => 4000,
            'balance_due' => 6000,
            'status' => 'partially_paid',
        ]);

        Sanctum::actingAs($primary);
        $primarySheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)
            ->assertOk()
            ->json();
        $this->assertEquals(4000, $primarySheet['income']);
        $this->assertEquals(2000, $primarySheet['expenses']);
        $this->assertEquals(2000, $primarySheet['net_profit']);

        Sanctum::actingAs($secondary);
        $secondarySheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)
            ->assertOk()
            ->json();
        $this->assertEquals(2600.0, $secondarySheet['income']);
        $this->assertEquals(1300.0, $secondarySheet['expenses']);
        $this->assertEquals(1300.0, $secondarySheet['net_profit']);
        $this->assertEquals(
            round($secondarySheet['income'] - $secondarySheet['expenses'], 2),
            $secondarySheet['net_profit']
        );

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertEquals(2600.0, $dashboard['today_income']);
    }

    public function test_disabling_dual_view_deactivates_secondary_login(): void
    {
        [$primary, $secondary] = $this->seedDualTenantWithBill();
        $admin = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null, 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/super-admin/tenants/{$primary->tenant_id}/dual-financial-view", [
            'enabled' => false,
        ])->assertOk()->assertJsonPath('tenant.dual_financial_view_enabled', false);

        $this->assertSame('inactive', $secondary->fresh()->status);
        $this->assertFalse((bool) $primary->tenant->fresh()->dual_financial_view_enabled);

        $this->postJson('/api/auth/login', [
            'email' => $secondary->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_secondary_can_read_parts_at_scaled_prices(): void
    {
        [, $secondary] = $this->seedDualTenantWithBill();
        $part = Part::create([
            'name' => 'Oil Filter', 'brand' => 'Toyota', 'type' => 'filter',
            'price' => 2000, 'cost_price' => 1000, 'stock_qty' => 5,
        ]);

        Sanctum::actingAs($secondary);
        $this->getJson("/api/parts/{$part->id}")
            ->assertOk()
            ->assertJsonPath('price', '1300.00')
            ->assertJsonPath('cost_price', '650.00');
    }

    /**
     * @return array{0: User, 1: User, 2: Bill}
     */
    private function seedDualTenantWithBill(): array
    {
        $features = collect([
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports',
        ])->map(fn (string $key) => Feature::firstOrCreate(
            ['key' => $key],
            ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0],
        ));

        $tenant = Tenant::create([
            'business_name' => 'Dual Garage',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => 'owner-dual@garage.lk',
            'status' => 'active',
            'dual_financial_view_enabled' => true,
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        $primary = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
            'email' => 'primary-dual@garage.lk',
            'password' => 'password',
            'is_secondary_view' => false,
        ]);
        $secondary = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
            'email' => 'secondary-dual@garage.lk',
            'password' => 'password',
            'is_secondary_view' => true,
        ]);

        Sanctum::actingAs($primary);
        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771112233',
            'number_plate' => 'ABC-1001',
            'chassis_number' => 'JTDBR32E100000001',
            'make' => 'Toyota',
            'model' => 'Aqua',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Service',
            'unit_price' => 10000,
        ])->assertCreated();

        return [$primary, $secondary, Bill::with('items')->findOrFail($billId)];
    }
}
