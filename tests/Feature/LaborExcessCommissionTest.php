<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaborExcessCommissionTest extends TestCase
{
    public function test_monthly_commission_is_ten_percent_of_paid_labor_above_salary(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'admit_service', 'billing', 'job_commissions', 'employees_management',
        ]);
        Sanctum::actingAs($owner);
        $mechanic = $this->employee('Mechanic', 50000);
        $washer = $this->employee('Washer', 600);
        $dayHand = Employee::create([
            'name' => 'Day Hand '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'daily',
            'daily_rate' => 3000,
            'base_salary' => 0,
        ]);

        $today = now()->toDateString();
        $repairId = $this->job('repair', [$mechanic->id, $dayHand->id], $today);
        $this->postJson("/api/bills/{$repairId}/items", [
            'type' => 'labor',
            'description' => 'Clutch labor',
            'quantity' => 1,
            'unit_price' => 80000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$repairId}/items", [
            'type' => 'part',
            'description' => 'Clutch kit',
            'quantity' => 1,
            'unit_price' => 5000,
            'purchase_unit_cost' => 3000,
        ])->assertCreated();
        $this->pay($repairId, $today);

        $washId = $this->job('service', [$washer->id], $today);
        $this->postJson("/api/bills/{$washId}/items", [
            'type' => 'service_addon',
            'description' => 'Body wash',
            'quantity' => 1,
            'unit_price' => 1000,
            'employee_id' => $washer->id,
        ])->assertCreated();
        $this->pay($washId, $today);

        $unpaidId = $this->job('repair', [$mechanic->id], $today);
        $this->postJson("/api/bills/{$unpaidId}/items", [
            'type' => 'labor',
            'description' => 'Unpaid labor',
            'quantity' => 1,
            'unit_price' => 20000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();

        $lastMonth = now()->subMonth()->toDateString();
        $oldId = $this->job('repair', [$mechanic->id], $lastMonth);
        $this->postJson("/api/bills/{$oldId}/items", [
            'type' => 'labor',
            'description' => 'Last month labor',
            'quantity' => 1,
            'unit_price' => 10000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();
        $this->pay($oldId, $lastMonth);

        $month = now()->format('Y-m');
        $report = $this->getJson('/api/commissions/month?month='.$month)->assertOk()->json();
        $this->assertSame('labor_excess', $report['type']);
        $this->assertEquals(3000, $report['commission_total']);
        $people = collect($report['employees']);
        $this->assertCount(2, $people);
        $this->assertNull($people->firstWhere('id', $dayHand->id));

        $mechanicRow = $people->firstWhere('id', $mechanic->id);
        $this->assertEquals(80000, $mechanicRow['labor_pool']);
        $this->assertEquals(80000, $mechanicRow['repair_counted']);
        $this->assertEquals(50000, $mechanicRow['salary']);
        $this->assertEquals(30000, $mechanicRow['excess']);
        $this->assertEquals(3000, $mechanicRow['commission']);
        $this->assertTrue($mechanicRow['has_commission']);

        $washerRow = $people->firstWhere('id', $washer->id);
        $this->assertEquals(500, $washerRow['labor_pool']);
        $this->assertEquals(500, $washerRow['service_counted']);
        $this->assertEquals(0, $washerRow['commission']);
        $this->assertFalse($washerRow['has_commission']);

        $days = $this->getJson("/api/commissions/month/{$mechanic->id}?month=".$month)->assertOk()->json();
        $jobs = collect($days['days'])->flatMap(fn (array $day) => $day['jobs']);
        $this->assertCount(1, $jobs);
        $job = $jobs->first();
        $this->assertSame($repairId, $job['bill_id']);
        $this->assertEquals(80000, $job['counted']);
        $this->assertEqualsCanonicalizing([$mechanic->name, $dayHand->name], $job['team']);
        $this->assertSame('Clutch labor', $job['lines'][0]['description']);
        $this->assertSame(100, $job['lines'][0]['share_percent']);

        $this->postJson("/api/bills/{$repairId}/commissions/{$mechanic->id}")->assertStatus(422);
    }

    public function test_a_mixed_job_counts_repair_in_full_and_service_at_half(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'admit_service', 'admit_combined', 'billing', 'job_commissions', 'employees_management',
        ]);
        Sanctum::actingAs($owner);
        $mechanic = $this->employee('Mix Mechanic', 0);
        $washer = $this->employee('Mix Washer', 0);
        $today = now()->toDateString();
        $billId = $this->job('both', [$mechanic->id, $washer->id], $today);
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'section' => 'repair',
            'description' => 'Brake labor',
            'quantity' => 1,
            'unit_price' => 4000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'section' => 'service',
            'description' => 'Body wash',
            'quantity' => 1,
            'unit_price' => 2000,
            'employee_id' => $washer->id,
        ])->assertCreated();
        $this->pay($billId, $today);

        $people = collect($this->getJson('/api/commissions/month?month='.now()->format('Y-m'))->assertOk()->json('employees'));
        $this->assertEquals(4000, $people->firstWhere('id', $mechanic->id)['labor_pool']);
        $this->assertEquals(400, $people->firstWhere('id', $mechanic->id)['commission']);
        $this->assertEquals(1000, $people->firstWhere('id', $washer->id)['labor_pool']);
        $this->assertEquals(100, $people->firstWhere('id', $washer->id)['commission']);
    }

    public function test_super_admin_chooses_the_commission_type_on_the_feature_plan(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'billing', 'job_commissions', 'employees_management',
        ]);
        $super = User::factory()->create([
            'tenant_id' => null,
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($super);

        $plan = $this->getJson('/api/super-admin/tenants/'.$owner->tenant_id.'/features')->assertOk()->json();
        $this->assertSame('labor_excess', $plan['commission_type']);
        $labor = collect($plan['commission_types'])->firstWhere('key', 'labor_excess');
        $this->assertNotNull($labor);
        $this->assertSame(50, $labor['rules']['service_share_percent']);
        $this->assertSame(100, $labor['rules']['repair_share_percent']);
        $this->assertStringContainsString('50%', $labor['summary']);
        $this->assertStringContainsString('100%', $labor['summary']);
        $this->assertStringContainsString('half days', $labor['summary']);

        $features = collect($plan['available'])->mapWithKeys(
            fn (array $feature) => [$feature['key'] => in_array($feature['key'], $plan['enabled'], true)]
        );
        $this->putJson('/api/super-admin/tenants/'.$owner->tenant_id.'/features', [
            'features' => $features->all(),
            'commission_type' => 'line_percent',
        ])->assertOk()->assertJsonPath('commission_type', 'line_percent');

        $this->assertSame('line_percent', $owner->tenant->fresh()->commission_type);

        $catalog = $this->getJson('/api/super-admin/feature-catalog?business_type=garage')->assertOk()->json('commission_types');
        $this->assertTrue(collect($catalog)->contains(fn (array $type) => $type['key'] === 'labor_excess' && $type['rules']['repair_share_percent'] === 100));
    }

    public function test_unassigned_labor_is_split_across_the_team(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'billing', 'job_commissions', 'employees_management',
        ]);
        Sanctum::actingAs($owner);
        $first = $this->employee('Split One', 0);
        $second = $this->employee('Split Two', 0);
        $dayHand = Employee::create([
            'name' => 'Split Day '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'daily',
            'daily_rate' => 2500,
        ]);
        $today = now()->toDateString();
        $billId = $this->job('repair', [$first->id, $second->id, $dayHand->id], $today);
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Shared labor',
            'quantity' => 1,
            'unit_price' => 1000,
        ])->assertCreated();
        $this->pay($billId, $today);

        $people = collect($this->getJson('/api/commissions/month?month='.now()->format('Y-m'))->assertOk()->json('employees'));
        $this->assertCount(3, $people);
        $this->assertEquals(333.33, $people->firstWhere('id', $first->id)['labor_pool']);
        $this->assertEquals(33.33, $people->firstWhere('id', $first->id)['commission']);
        $this->assertEquals(333.33, $people->firstWhere('id', $second->id)['labor_pool']);
        $this->assertEquals(33.33, $people->firstWhere('id', $second->id)['commission']);
        $dayRow = $people->firstWhere('id', $dayHand->id);
        $this->assertEquals(333.34, $dayRow['labor_pool']);
        $this->assertFalse($dayRow['salary_ready']);
        $this->assertEquals(0, $dayRow['commission']);
    }

    public function test_attendance_builds_the_salary_commission_compares(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'billing', 'job_commissions', 'employees_management', 'attendance',
        ]);
        Sanctum::actingAs($owner);
        $daily = $this->postJson('/api/employees', [
            'name' => 'Daily Hand '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'daily',
            'amount' => 2000,
        ])->assertCreated()->json();
        $this->assertEquals(2000, $daily['daily_rate']);
        $this->assertEquals(0, $daily['base_salary']);

        $monthly = $this->postJson('/api/employees', [
            'name' => 'Month Hand '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'monthly',
            'amount' => 50000,
        ])->assertCreated()->json();
        $this->assertEquals(50000, $monthly['base_salary']);
        $this->assertNull($monthly['daily_rate']);

        $today = now()->toDateString();
        $dailyJob = $this->job('repair', [$daily['id']], $today);
        $this->postJson("/api/bills/{$dailyJob}/items", [
            'type' => 'labor',
            'description' => 'Daily labor',
            'quantity' => 1,
            'unit_price' => 50000,
            'employee_id' => $daily['id'],
        ])->assertCreated();
        $this->pay($dailyJob, $today);

        $month = now()->format('Y-m');
        $before = collect($this->getJson('/api/commissions/month?month='.$month)->assertOk()->json('employees'))
            ->firstWhere('id', $daily['id']);
        $this->assertEquals(50000, $before['labor_pool']);
        $this->assertFalse($before['salary_ready']);
        $this->assertEquals(0, $before['commission']);

        $this->putJson('/api/attendance/pay', [
            'employee_id' => $daily['id'],
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'days' => 10,
            'half_days' => 1,
            'additions' => [['name' => 'Bonus', 'amount' => 500]],
            'deductions' => [['name' => 'Advance', 'amount' => 200]],
        ])->assertOk()->assertJsonPath('salary', 21300);

        $after = collect($this->getJson('/api/commissions/month?month='.$month)->assertOk()->json('employees'))
            ->firstWhere('id', $daily['id']);
        $this->assertTrue($after['salary_ready']);
        $this->assertEquals(21300, $after['salary']);
        $this->assertEquals(28700, $after['excess']);
        $this->assertEquals(2870, $after['commission']);

        $monthJob = $this->job('repair', [$monthly['id']], $today);
        $this->postJson("/api/bills/{$monthJob}/items", [
            'type' => 'labor',
            'description' => 'Monthly labor',
            'quantity' => 1,
            'unit_price' => 80000,
            'employee_id' => $monthly['id'],
        ])->assertCreated();
        $this->pay($monthJob, $today);
        $this->putJson('/api/attendance/pay', [
            'employee_id' => $monthly['id'],
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'deductions' => [['name' => 'Advance', 'amount' => 5000]],
        ])->assertOk()->assertJsonPath('salary', 45000)->assertJsonPath('salary_ready', true);

        $monthRow = collect($this->getJson('/api/commissions/month?month='.$month)->assertOk()->json('employees'))
            ->firstWhere('id', $monthly['id']);
        $this->assertEquals(45000, $monthRow['salary']);
        $this->assertEquals(3500, $monthRow['commission']);
    }

    private function employee(string $name, float $salary): Employee
    {
        return Employee::create([
            'name' => $name.' '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'monthly',
            'base_salary' => $salary,
        ]);
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function job(string $kind, array $employeeIds, string $date): int
    {
        return $this->postJson('/api/bills', [
            'number_plate' => 'EXC-'.fake()->unique()->numerify('####'),
            'admission_date' => $date,
            'job_kind' => $kind,
            'employee_ids' => $employeeIds,
        ])->assertCreated()->json('id');
    }

    private function pay(int $billId, string $paidOn): void
    {
        $due = (float) $this->getJson("/api/bills/{$billId}")->json('balance_due');
        $this->postJson("/api/bills/{$billId}/payments", [
            'amount' => $due,
            'method' => 'cash',
            'paid_at' => $paidOn,
        ])->assertCreated();
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
}
