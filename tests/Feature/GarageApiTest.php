<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Employee;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GarageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_a_token(): void
    {
        $this->tenantUser('business_owner', ['email' => 'admin@garage.lk', 'password' => 'password']);

        $this->postJson('/api/auth/login', ['email' => 'admin@garage.lk', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'features', 'user' => ['id', 'tenant_id', 'name', 'email', 'role', 'tenant']]);
    }

    public function test_cashier_can_admit_a_vehicle_and_manage_bill_items_and_payments(): void
    {
        $cashier = $this->tenantUser('staff');
        Sanctum::actingAs($cashier);
        $part = Part::create([
            'name' => 'Brake Pad Set', 'brand' => 'Akebono', 'type' => 'brake',
            'model' => 'Corolla', 'year' => 2018, 'price' => 12500, 'cost_price' => 8000, 'stock_qty' => 10,
        ]);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1234',
            'chassis_number' => 'JTDBR32E123456789',
            'make' => 'Toyota',
            'model' => 'Corolla',
        ])->assertCreated()->json('id');

        $itemId = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'part_id' => $part->id, 'quantity' => 2,
        ])->assertCreated()->assertJsonPath('bill.subtotal', '25000.00')->json('item.id');

        $this->assertSame(8, $part->fresh()->stock_qty);

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'discount', 'description' => 'Loyalty discount', 'quantity' => 1, 'unit_price' => 1000,
        ])->assertCreated()->assertJsonPath('bill.balance_due', '24000.00');

        $this->postJson("/api/bills/{$billId}/payments", [
            'amount' => 10000, 'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('bill.status', 'partially_paid')
            ->assertJsonPath('bill.amount_paid', '10000.00')
            ->assertJsonPath('bill.balance_due', '14000.00');

        $this->deleteJson("/api/bills/{$billId}/items/{$itemId}")->assertOk();
        $this->assertSame(10, $part->fresh()->stock_qty);
        $this->assertSame('0.00', Bill::find($billId)->subtotal);
    }

    public function test_staff_cannot_mutate_inventory_or_use_an_unpermitted_feature(): void
    {
        $staff = $this->tenantUser('staff');
        $balanceSheet = Feature::where('key', 'balance_sheet')->firstOrFail();
        $staff->permissions()->updateExistingPivot($balanceSheet->id, ['can_access' => false]);
        Sanctum::actingAs($staff);

        $this->postJson('/api/parts', [])->assertForbidden();
        $this->getJson('/api/balance-sheet?month=7&year=2026')->assertForbidden();
    }

    public function test_fingerprint_ingestion_and_payroll_feed_balance_sheet(): void
    {
        $admin = $this->tenantUser('business_owner');
        Sanctum::actingAs($admin);
        $employee = Employee::create([
            'name' => 'Kamal Silva', 'nic' => '901234567V', 'phone' => '0712345678',
            'position' => 'Mechanic', 'base_salary' => 80000, 'overtime_hourly_rate' => 1000,
            'fingerprint_id' => 'FP-001',
        ]);

        $headers = ['X-Device-Key' => 'change-this-device-key'];
        $this->withHeaders($headers)->postJson('/api/attendance/ingest', [
            'tenant_id' => $admin->tenant_id, 'fingerprint_id' => 'FP-001', 'timestamp' => '2026-07-06 08:00:00', 'event' => 'check_in',
        ])->assertCreated();
        $this->withHeaders($headers)->postJson('/api/attendance/ingest', [
            'tenant_id' => $admin->tenant_id, 'fingerprint_id' => 'FP-001', 'timestamp' => '2026-07-06 18:00:00', 'event' => 'check_out',
        ])->assertOk()->assertJsonPath('hours_worked', '10.00')->assertJsonPath('overtime_hours', '2.00');

        Sanctum::actingAs($admin);
        $this->postJson('/api/payroll/generate', [
            'month' => 7, 'year' => 2026, 'bonuses' => [$employee->id => 5000], 'deductions' => [$employee->id => 2000],
        ])->assertOk()->assertJsonPath('data.0.net_salary', '85000.00');

        $this->getJson('/api/balance-sheet?month=7&year=2026')
            ->assertOk()
            ->assertJsonPath('expenses', 85000)
            ->assertJsonPath('expense_breakdown.salary', 85000)
            ->assertJsonPath('net_profit', -85000);
    }

    private function tenantUser(string $role, array $attributes = []): User
    {
        $features = collect(['admit_vehicle', 'billing', 'payroll', 'balance_sheet', 'parts_inventory', 'employees_management', 'reports'])
            ->map(fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline()]));
        $tenant = Tenant::create([
            'business_name' => fake()->company(), 'business_type' => 'garage', 'owner_name' => fake()->name(),
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);
        if ($role === 'staff') {
            $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));
        }

        return $user;
    }
}
