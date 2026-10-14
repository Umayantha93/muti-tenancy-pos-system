<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BranchContext;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JobCardPayrollTest extends TestCase
{
    public function test_job_card_staff_can_look_up_parts_but_cannot_change_inventory(): void
    {
        $owner = $this->userWith(['billing', 'parts_inventory', 'employees_management']);
        Sanctum::actingAs($owner);
        $part = Part::create([
            'name' => 'Lookup pad '.fake()->unique()->numerify('###'),
            'brand' => 'Test',
            'type' => 'brake',
            'price' => 1200,
            'stock_qty' => 4,
        ]);

        $staff = $this->staffWith($owner, ['billing']);
        Sanctum::actingAs($staff);

        $this->getJson('/api/parts?search='.urlencode($part->name))
            ->assertOk()
            ->assertJsonFragment(['id' => $part->id, 'name' => $part->name]);
        $this->postJson('/api/parts', ['name' => 'Blocked'])->assertForbidden();
        $this->postJson('/api/labor-categories', ['name' => 'Staff labor '.fake()->unique()->numerify('###')])->assertCreated();

        $locked = $this->staffWith($owner, []);
        Sanctum::actingAs($locked);
        $this->getJson('/api/parts')->assertForbidden();
        $this->postJson('/api/labor-categories', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_combined_job_keeps_service_and_repair_sections_on_one_card(): void
    {
        $owner = $this->userWith(['admit_vehicle', 'admit_repair', 'admit_service', 'billing']);
        $enabled = $this->userWith(['admit_vehicle', 'admit_repair', 'admit_service', 'admit_combined', 'billing', 'parts_inventory']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/bills', [
            'number_plate' => 'CMB-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'both',
        ])->assertForbidden();

        BranchContext::clear();
        Sanctum::actingAs($enabled);
        $part = Part::create([
            'name' => 'Combined filter '.fake()->unique()->numerify('###'),
            'brand' => 'Test',
            'type' => 'filter',
            'price' => 900,
            'cost_price' => 400,
            'stock_qty' => 5,
        ]);
        $billId = $this->postJson('/api/bills', [
            'number_plate' => 'CMB-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'both',
        ])->assertCreated()->assertJsonPath('job_kind', 'both')->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'section' => 'service',
            'description' => 'Oil service',
            'quantity' => 1,
            'unit_price' => 2500,
        ])->assertCreated()->assertJsonPath('item.section', 'service');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'section' => 'repair',
            'description' => 'Brake labor',
            'quantity' => 1,
            'unit_price' => 4000,
        ])->assertCreated()->assertJsonPath('item.section', 'repair');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part',
            'section' => 'service',
            'part_id' => $part->id,
            'quantity' => 1,
        ])->assertCreated()->assertJsonPath('item.section', 'service');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'section' => 'service',
            'description' => 'Wrong section',
            'quantity' => 1,
            'unit_price' => 100,
        ])->assertStatus(422);
    }

    public function test_daily_wage_is_paid_once_and_posts_a_named_finance_line(): void
    {
        $owner = $this->userWith(['employees_management', 'payroll', 'balance_sheet']);
        Sanctum::actingAs($owner);
        $name = 'Day Worker '.fake()->unique()->numerify('###');
        $employeeId = $this->postJson('/api/employees', [
            'name' => $name,
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'daily',
            'daily_rate' => 3500,
        ])->assertCreated()->assertJsonPath('pay_type', 'daily')->json('id');

        $this->postJson('/api/employees', [
            'name' => 'Needs a rate',
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'daily',
        ])->assertStatus(422);

        $paidOn = now()->toDateString();
        $this->postJson('/api/payroll/day-wages', [
            'employee_id' => $employeeId,
            'paid_on' => $paidOn,
            'amount' => 3200,
        ])->assertCreated();
        $this->postJson('/api/payroll/day-wages', [
            'employee_id' => $employeeId,
            'paid_on' => $paidOn,
            'amount' => 3200,
        ])->assertStatus(422);

        $accounts = collect($this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json('accounts'));
        $line = $accounts->first(fn (array $row) => ($row['description'] ?? '') === 'Payment for employee · '.$name);
        $this->assertNotNull($line);
        $this->assertSame('Employee payments', $line['category']);
        $this->assertEquals(3200, $line['debit']);
    }

    public function test_commission_deduct_posts_once_and_lists_the_job_on_the_employee(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'billing', 'job_commissions',
            'employees_management', 'balance_sheet',
        ]);
        Sanctum::actingAs($owner);
        $name = 'Commission Hand '.fake()->unique()->numerify('###');
        $employee = Employee::create([
            'name' => $name,
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        $bill = $this->postJson('/api/bills', [
            'number_plate' => 'COM-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'repair',
            'employee_ids' => [$employee->id],
        ])->assertCreated()->json();

        $this->postJson("/api/bills/{$bill['id']}/items", [
            'type' => 'labor',
            'description' => 'Clutch labor',
            'quantity' => 1,
            'unit_price' => 8000,
        ])->assertCreated();
        $this->postJson("/api/bills/{$bill['id']}/items", [
            'type' => 'part',
            'description' => 'Clutch kit',
            'quantity' => 1,
            'unit_price' => 5000,
            'purchase_unit_cost' => 3000,
        ])->assertCreated();

        $deducted = $this->postJson("/api/bills/{$bill['id']}/commissions/{$employee->id}")
            ->assertOk()
            ->json();
        $pivot = collect($deducted['employees'])->firstWhere('id', $employee->id);
        $this->assertEquals(800, $pivot['pivot']['commission_amount']);
        $this->assertNotNull($pivot['pivot']['commission_deducted_at']);

        $this->postJson("/api/bills/{$bill['id']}/commissions/{$employee->id}")->assertStatus(422);

        $accounts = collect($this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json('accounts'));
        $line = $accounts->first(fn (array $row) => ($row['description'] ?? '') === 'Commission · '.$name);
        $this->assertNotNull($line);
        $this->assertSame($bill['bill_number'], $line['reference']);
        $this->assertSame('Employee payments', $line['category']);
        $this->assertEquals(800, $line['debit']);

        $shown = $this->getJson("/api/employees/{$employee->id}")->assertOk()->json();
        $past = collect($shown['bills'])->firstWhere('id', $bill['id']);
        $this->assertNotNull($past);
        $this->assertEquals(800, $past['pivot']['commission_amount']);
    }

    /**
     * @param  list<string>  $keys
     */
    private function userWith(array $keys): User
    {
        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(),
            'business_type' => 'garage',
            'owner_name' => fake()->name(),
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'business_owner',
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function staffWith(User $owner, array $keys): User
    {
        $staff = User::factory()->create([
            'tenant_id' => $owner->tenant_id,
            'role' => 'staff',
            'status' => 'active',
        ]);
        $features = Feature::query()->whereIn('key', $keys)->get();
        $staff->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));

        return $staff;
    }
}
