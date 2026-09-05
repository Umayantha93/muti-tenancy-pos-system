<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeTarget;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerOpsTest extends TestCase
{

    public function test_credit_inventory_can_be_settled_in_steps_and_shows_remaining(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $part = Part::create([
            'name' => 'Brake Pads', 'brand' => 'Nissin', 'type' => 'brake',
            'price' => 7800, 'cost_price' => 6200, 'stock_qty' => 2,
        ]);

        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 50,
            'unit_cost' => 6200,
            'payment_status' => 'credit',
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertOk();

        $sheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertEquals(0, $sheet['expenses']);
        $this->assertEquals(310000, $sheet['inventory_payables']['payables_total']);
        $this->assertEquals(310000, $sheet['inventory_payables']['items'][0]['remaining']);

        $expenseId = Expense::credit()->first()->id;
        $this->postJson("/api/expenses/{$expenseId}/settle", ['amount' => 100000])->assertOk()
            ->assertJsonPath('remaining', 210000);

        $partial = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertEquals(100000, $partial['expenses']);
        $this->assertEquals(210000, $partial['inventory_payables']['payables_total']);

        $this->postJson("/api/expenses/{$expenseId}/settle")->assertOk()
            ->assertJsonPath('remaining', 0);

        $settled = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertEquals(310000, $settled['expenses']);
        $this->assertEquals(0, $settled['inventory_payables']['payables_total']);
    }

    public function test_team_and_employee_targets_accept_daily_progress(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $employee = Employee::create([
            'name' => 'Sewu', 'nic' => '199012345678', 'phone' => '0771112222',
            'position' => 'Tailor', 'base_salary' => 50000, 'fingerprint_id' => 'fp-1', 'active' => true,
        ]);

        $team = $this->postJson('/api/employee-targets', [
            'scope' => 'team',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->endOfMonth()->toDateString(),
            'kind' => 'pieces',
            'amount' => 100,
            'incentive_amount' => 2000,
        ])->assertCreated()->json();
        $this->assertNull($team['employee_id']);

        $teamProgress = $this->postJson("/api/employee-targets/{$team['id']}/progress", [
            'employee_id' => $employee->id,
            'work_date' => now()->toDateString(),
            'amount' => 40,
        ])->assertOk()->json();
        $this->assertEquals(40, (float) $teamProgress['progress_amount']);

        $personal = $this->postJson('/api/employee-targets', [
            'scope' => 'employee',
            'employee_id' => $employee->id,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->endOfMonth()->toDateString(),
            'kind' => 'pieces',
            'amount' => 20,
            'incentive_amount' => 500,
        ])->assertCreated()->json();

        $personalProgress = $this->postJson("/api/employee-targets/{$personal['id']}/progress", [
            'employee_id' => $employee->id,
            'work_date' => now()->toDateString(),
            'amount' => 20,
        ])->assertOk()->json();
        $this->assertEquals(20, (float) $personalProgress['progress_amount']);
    }

    public function test_staff_applies_leave_and_owner_approves(): void
    {
        $owner = $this->garageUser();
        Sanctum::actingAs($owner);
        $employee = Employee::create([
            'name' => 'Kasun', 'nic' => '199112345678', 'phone' => '0773334444',
            'position' => 'Cashier', 'base_salary' => 40000, 'fingerprint_id' => 'fp-2',
            'active' => true, 'paid_leave_days_per_year' => 14,
        ]);
        $staff = User::factory()->create([
            'tenant_id' => $owner->tenant_id, 'role' => 'staff', 'status' => 'active', 'employee_id' => $employee->id,
        ]);
        $billing = Feature::firstOrCreate(['key' => 'billing'], ['name' => 'Billing', 'group' => 'Service Intake', 'sort_order' => 0]);
        $staff->permissions()->sync([$billing->id => ['can_access' => true]]);

        Sanctum::actingAs($staff);
        $leave = $this->postJson('/api/me/leaves', [
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'type' => 'paid',
            'notes' => 'Family',
        ])->assertCreated()->json();
        $this->assertSame(EmployeeLeave::STATUS_PENDING, $leave['status']);

        Sanctum::actingAs($owner);
        $this->postJson("/api/employee-leaves/{$leave['id']}/approve")->assertOk()
            ->assertJsonPath('status', EmployeeLeave::STATUS_APPROVED);
    }

    public function test_expired_demo_tenant_cannot_log_in_and_admin_can_activate(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'tenant_id' => null]);
        $tenant = Tenant::create([
            'business_name' => 'Demo Garage', 'business_type' => 'garage', 'owner_name' => 'Amal',
            'owner_phone' => '0771234567', 'owner_email' => 'amal@example.com', 'status' => 'active',
            'demo_ends_at' => now()->subDay(),
        ]);
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active', 'email' => 'amal@example.com',
        ]);

        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertUnprocessable();
        $this->assertSame('inactive', $tenant->fresh()->status);

        Sanctum::actingAs($admin);
        $this->postJson("/api/super-admin/tenants/{$tenant->id}/activate")->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('demo_ends_at', null);
        $this->postJson("/api/super-admin/tenants/{$tenant->id}/demo", ['days' => 21])->assertOk();
        $this->assertNotNull($tenant->fresh()->demo_ends_at);
        $this->assertSame('active', $tenant->fresh()->status);
    }

    public function test_deactivated_employee_can_be_reactivated(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $employee = Employee::create([
            'name' => 'Nimal', 'nic' => '198812345678', 'phone' => '0775556666',
            'position' => 'Mechanic', 'base_salary' => 60000, 'fingerprint_id' => 'fp-3', 'active' => true,
        ]);
        $this->deleteJson("/api/employees/{$employee->id}")->assertNoContent();
        $this->assertFalse($employee->fresh()->active);
        $this->postJson("/api/employees/{$employee->id}/activate")->assertOk()
            ->assertJsonPath('active', true);
    }

    /**
     * @param  list<string>  $extraFeatures
     */
    private function garageUser(array $extraFeatures = []): User
    {
        $keys = [
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports',
            ...$extraFeatures,
        ];
        $features = collect($keys)->unique()->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(), 'business_type' => 'garage', 'owner_name' => fake()->name(),
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);
    }
}
