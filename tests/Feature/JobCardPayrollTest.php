<?php

namespace Tests\Feature;

use App\Models\Bill;
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
            'employee_id' => $employee->id,
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

    public function test_commission_follows_the_lines_each_technician_did(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'admit_service', 'admit_combined', 'billing', 'job_commissions',
            'employees_management',
        ]);
        Sanctum::actingAs($owner);
        $washer = Employee::create([
            'name' => 'Washer '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        $mechanic = Employee::create([
            'name' => 'Mechanic '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        $billId = $this->postJson('/api/bills', [
            'number_plate' => 'SPL-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'both',
            'employee_ids' => [$washer->id, $mechanic->id],
        ])->assertCreated()->json('id');

        $washId = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'section' => 'service',
            'description' => 'Body wash',
            'quantity' => 1,
            'unit_price' => 2000,
            'employee_id' => $washer->id,
        ])->assertCreated()->json('item.id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'section' => 'service',
            'description' => 'Full service',
            'quantity' => 1,
            'unit_price' => 3000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'section' => 'repair',
            'description' => 'Brake pad replacement',
            'quantity' => 1,
            'unit_price' => 4000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part',
            'section' => 'repair',
            'description' => 'Brake pads',
            'quantity' => 1,
            'unit_price' => 9000,
            'purchase_unit_cost' => 5000,
        ])->assertCreated();

        $washerPay = $this->postJson("/api/bills/{$billId}/commissions/{$washer->id}")->assertOk()->json();
        $washerPivot = collect($washerPay['employees'])->firstWhere('id', $washer->id);
        $this->assertEquals(200, $washerPivot['pivot']['commission_amount']);

        $mechanicPay = $this->postJson("/api/bills/{$billId}/commissions/{$mechanic->id}")->assertOk()->json();
        $mechanicPivot = collect($mechanicPay['employees'])->firstWhere('id', $mechanic->id);
        $this->assertEquals(700, $mechanicPivot['pivot']['commission_amount']);

        $this->postJson("/api/bills/{$billId}/commissions/{$washer->id}")->assertStatus(422);
        $this->putJson("/api/bills/{$billId}/items/{$washId}", [
            'employee_id' => $mechanic->id,
        ])->assertStatus(422);
    }

    public function test_commission_board_shows_what_each_technician_might_receive(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'admit_service', 'admit_combined', 'billing', 'job_commissions', 'employees_management',
        ]);
        Sanctum::actingAs($owner);
        $washer = Employee::create([
            'name' => 'Board Washer '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        $mechanic = Employee::create([
            'name' => 'Board Mechanic '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        Employee::create([
            'name' => 'Board Day Wage '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'daily',
            'daily_rate' => 3500,
        ]);

        $today = now()->toDateString();
        $billId = $this->postJson('/api/bills', [
            'number_plate' => 'BRD-'.fake()->unique()->numerify('####'),
            'admission_date' => $today,
            'job_kind' => 'both',
            'employee_ids' => [$washer->id, $mechanic->id],
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'section' => 'service',
            'description' => 'Body wash',
            'quantity' => 1,
            'unit_price' => 2000,
            'employee_id' => $washer->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon',
            'section' => 'service',
            'description' => 'Full service',
            'quantity' => 1,
            'unit_price' => 3000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'section' => 'repair',
            'description' => 'Brake pad replacement',
            'quantity' => 1,
            'unit_price' => 4000,
            'employee_id' => $mechanic->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part',
            'section' => 'repair',
            'description' => 'Brake pads',
            'quantity' => 1,
            'unit_price' => 9000,
            'purchase_unit_cost' => 5000,
        ])->assertCreated();

        $yesterday = $this->postJson('/api/bills', [
            'number_plate' => 'OLD-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->subDay()->toDateString(),
            'job_kind' => 'service',
            'employee_ids' => [$washer->id],
        ])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$yesterday}/items", [
            'type' => 'service_addon',
            'description' => 'Yesterday wash',
            'quantity' => 1,
            'unit_price' => 5000,
            'employee_id' => $washer->id,
        ])->assertCreated();

        $board = $this->getJson('/api/commissions/day?date='.$today)->assertOk()->json();
        $this->assertEquals(900, $board['might_total']);
        $people = collect($board['employees']);
        $this->assertCount(2, $people);
        $washerRow = $people->firstWhere('id', $washer->id);
        $mechanicRow = $people->firstWhere('id', $mechanic->id);
        $this->assertEquals(200, $washerRow['might_receive']);
        $this->assertEquals(0, $washerRow['deducted']);
        $this->assertEquals(200, $washerRow['pending']);
        $this->assertSame(['Body wash'], $washerRow['jobs'][0]['lines']);
        $this->assertEquals(700, $mechanicRow['might_receive']);
        $this->assertEquals(700, $mechanicRow['pending']);
        $this->assertEqualsCanonicalizing(['Full service', 'Brake pad replacement'], $mechanicRow['jobs'][0]['lines']);

        $this->postJson("/api/bills/{$billId}/commissions/{$washer->id}")->assertOk();
        $after = collect($this->getJson('/api/commissions/day?date='.$today)->assertOk()->json('employees'));
        $washerAfter = $after->firstWhere('id', $washer->id);
        $this->assertEquals(200, $washerAfter['might_receive']);
        $this->assertEquals(200, $washerAfter['deducted']);
        $this->assertEquals(0, $washerAfter['pending']);
        $this->assertTrue($washerAfter['jobs'][0]['deducted']);
        $this->assertEquals(900, $this->getJson('/api/commissions/day?date='.$today)->json('might_total'));
    }

    public function test_free_service_zeros_the_customer_bill_and_still_splits_commission(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'admit_service', 'admit_combined', 'billing',
            'job_commissions', 'employees_management', 'free_service', 'bill_profits', 'parts_inventory',
        ]);
        Sanctum::actingAs($owner);
        $washer = Employee::create([
            'name' => 'Free Washer '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        $mechanic = Employee::create([
            'name' => 'Free Mechanic '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        $part = Part::create([
            'name' => 'Free pads '.fake()->unique()->numerify('###'),
            'brand' => 'Test',
            'type' => 'brake',
            'price' => 9000,
            'cost_price' => 5000,
            'stock_qty' => 5,
        ]);

        $billId = $this->postJson('/api/bills', [
            'number_plate' => 'FRE-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'both',
            'employee_ids' => [$washer->id, $mechanic->id],
        ])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon', 'section' => 'service', 'description' => 'Body wash',
            'quantity' => 1, 'unit_price' => 2000, 'employee_id' => $washer->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'service_addon', 'section' => 'service', 'description' => 'Full service',
            'quantity' => 1, 'unit_price' => 3000, 'employee_id' => $mechanic->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor', 'section' => 'repair', 'description' => 'Brake pad replacement',
            'quantity' => 1, 'unit_price' => 4000, 'employee_id' => $mechanic->id,
        ])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part', 'section' => 'repair', 'part_id' => $part->id, 'quantity' => 1,
        ])->assertCreated();
        $part->refresh();
        $this->assertSame(4, (int) $part->stock_qty);

        Bill::query()->findOrFail($billId)->forceFill(['vat_rate' => 18, 'sscl_rate' => 2.5])->save();
        $priced = $this->putJson("/api/bills/{$billId}/free", ['is_free' => false])->assertOk()->json();
        $this->assertGreaterThan(0, (float) $priced['vat_amount']);
        $this->assertGreaterThan(0, (float) $priced['balance_due']);
        $subtotal = $priced['subtotal'];

        $this->putJson("/api/bills/{$billId}/free", ['is_free' => true])->assertStatus(422);
        $free = $this->putJson("/api/bills/{$billId}/free", [
            'is_free' => true,
            'reason' => 'Warranty from the manufacturer',
        ])->assertOk()->json();
        $this->assertTrue($free['is_free']);
        $this->assertSame('Warranty from the manufacturer', $free['free_reason']);
        $this->assertEquals(0, $free['balance_due']);
        $this->assertEquals(0, $free['vat_amount']);
        $this->assertEquals(0, $free['sscl_amount']);
        $this->assertEquals($subtotal, $free['subtotal']);
        $part->refresh();
        $this->assertSame(4, (int) $part->stock_qty);

        $washerPay = $this->postJson("/api/bills/{$billId}/commissions/{$washer->id}")->assertOk()->json();
        $this->assertEquals(200, collect($washerPay['employees'])->firstWhere('id', $washer->id)['pivot']['commission_amount']);
        $mechanicPay = $this->postJson("/api/bills/{$billId}/commissions/{$mechanic->id}")->assertOk()->json();
        $this->assertEquals(700, collect($mechanicPay['employees'])->firstWhere('id', $mechanic->id)['pivot']['commission_amount']);

        $profit = $this->getJson("/api/bill-profits/{$billId}")->assertOk()->json();
        $this->assertEquals(0, $profit['revenue']);
        $this->assertEquals(5000, $profit['cogs']);

        $closed = $this->postJson("/api/bills/{$billId}/close")->assertOk()->json();
        $this->assertSame('closed', $closed['status']);
        $this->assertTrue($closed['is_free']);
        $this->assertSame('Warranty from the manufacturer', $closed['free_reason']);
        $this->assertEquals(0, $closed['balance_due']);
        $this->getJson('/api/bills/shared/'.$closed['share_token'])
            ->assertOk()
            ->assertJsonPath('free_reason', 'Warranty from the manufacturer');
        $this->putJson("/api/bills/{$billId}/free", ['is_free' => false])->assertStatus(422);

        $paidId = $this->postJson('/api/bills', [
            'number_plate' => 'PAY-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'repair',
        ])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$paidId}/items", [
            'type' => 'labor', 'description' => 'Paid labor', 'quantity' => 1, 'unit_price' => 1000,
        ])->assertCreated();
        $this->postJson("/api/bills/{$paidId}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->putJson("/api/bills/{$paidId}/free", ['is_free' => true])->assertStatus(422);
    }

    public function test_line_technician_name_is_on_the_bill_only_when_the_feature_is_on(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'billing', 'employees_management', 'show_bill_technicians',
        ]);
        Sanctum::actingAs($owner);
        $name = 'Named Tech '.fake()->unique()->numerify('###');
        $employee = Employee::create([
            'name' => $name,
            'nic' => fake()->unique()->numerify('##########V'),
            'pay_type' => 'monthly',
        ]);
        $bill = $this->postJson('/api/bills', [
            'number_plate' => 'TEC-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'repair',
            'employee_ids' => [$employee->id],
        ])->assertCreated()->json();
        $itemId = $this->postJson("/api/bills/{$bill['id']}/items", [
            'type' => 'labor',
            'description' => 'Clutch labor',
            'quantity' => 1,
            'unit_price' => 1500,
            'employee_id' => $employee->id,
        ])->assertCreated()->json('item.id');

        $shown = collect($this->getJson("/api/bills/{$bill['id']}")->assertOk()->json('items'))->firstWhere('id', $itemId);
        $this->assertSame($name, $shown['technician_name']);
        $shared = collect($this->getJson('/api/bills/shared/'.$bill['share_token'])->assertOk()->json('items'))->firstWhere('id', $itemId);
        $this->assertSame($name, $shared['technician_name']);

        $feature = Feature::query()->where('key', 'show_bill_technicians')->firstOrFail();
        $owner->tenant->features()->updateExistingPivot($feature->id, ['is_enabled' => false]);

        $hidden = collect($this->getJson("/api/bills/{$bill['id']}")->assertOk()->json('items'))->firstWhere('id', $itemId);
        $this->assertArrayNotHasKey('technician_name', $hidden);
        $sharedHidden = collect($this->getJson('/api/bills/shared/'.$bill['share_token'])->assertOk()->json('items'))->firstWhere('id', $itemId);
        $this->assertArrayNotHasKey('technician_name', $sharedHidden);
    }

    public function test_line_discount_lowers_the_total_and_flags_a_sale_under_cost(): void
    {
        $owner = $this->userWith([
            'admit_vehicle', 'admit_repair', 'billing', 'parts_inventory', 'line_discounts', 'job_commissions', 'employees_management',
        ]);
        Sanctum::actingAs($owner);
        $part = Part::create([
            'name' => 'Discount pad '.fake()->unique()->numerify('###'),
            'brand' => 'Test',
            'type' => 'brake',
            'price' => 8000,
            'cost_price' => 5000,
            'stock_qty' => 4,
        ]);
        $mechanic = Employee::create([
            'name' => 'Discount Mechanic '.fake()->unique()->numerify('###'),
            'nic' => fake()->unique()->numerify('##########V'),
            'commission_percent' => 10,
            'pay_type' => 'monthly',
        ]);
        $billId = $this->postJson('/api/bills', [
            'number_plate' => 'DSC-'.fake()->unique()->numerify('####'),
            'admission_date' => now()->toDateString(),
            'job_kind' => 'repair',
            'employee_ids' => [$mechanic->id],
        ])->assertCreated()->json('id');
        $partItem = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part',
            'part_id' => $part->id,
            'quantity' => 1,
        ])->assertCreated()->json('item');
        $laborId = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Brake labor',
            'quantity' => 1,
            'unit_price' => 4000,
            'employee_id' => $mechanic->id,
        ])->assertCreated()->json('item.id');

        $blocked = $this->staffWith($owner, ['billing']);
        Sanctum::actingAs($blocked);
        $this->putJson("/api/bills/{$billId}/items/{$partItem['id']}", ['line_discount' => 100])->assertForbidden();

        Sanctum::actingAs($owner);
        $under = $this->putJson("/api/bills/{$billId}/items/{$partItem['id']}", ['line_discount' => 4000])->assertOk()->json('item');
        $this->assertEquals(4000, $under['line_total']);
        $this->assertTrue($under['below_cost']);

        $safe = $this->putJson("/api/bills/{$billId}/items/{$partItem['id']}", ['line_discount' => 2000])->assertOk()->json('item');
        $this->assertEquals(6000, $safe['line_total']);
        $this->assertFalse($safe['below_cost']);

        $this->putJson("/api/bills/{$billId}/items/{$partItem['id']}", ['line_discount' => 9000])->assertStatus(422);

        $labor = $this->putJson("/api/bills/{$billId}/items/{$laborId}", ['line_discount' => 1000])->assertOk()->json('item');
        $this->assertEquals(3000, $labor['line_total']);
        $this->assertFalse($labor['below_cost']);
        $pay = $this->postJson("/api/bills/{$billId}/commissions/{$mechanic->id}")->assertOk()->json();
        $this->assertEquals(300, collect($pay['employees'])->firstWhere('id', $mechanic->id)['pivot']['commission_amount']);
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
        $tenant->update(['commission_type' => 'line_percent']);
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
