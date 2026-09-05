<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Employee;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JobStaffNotesAndEmployeesTest extends TestCase
{

    public function test_job_can_be_opened_without_customer_name_or_phone(): void
    {
        Sanctum::actingAs($this->garageUser());

        $bill = $this->postJson('/api/bills', [
            'number_plate' => 'CAB-9999',
            'internal_notes' => 'Paint chips on rear bumper',
        ])->assertCreated()->json();

        $this->assertSame('Walk-in', $bill['customer']['name']);
        $this->assertNull($bill['customer']['phone']);
        $this->assertSame('Paint chips on rear bumper', $bill['internal_notes']);
        $this->assertSame([], $bill['employees']);
    }

    public function test_additional_notes_appear_on_the_customer_share_bill_for_garage(): void
    {
        Sanctum::actingAs($this->garageUser());

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1234',
            'notes' => 'Customer-facing note',
            'internal_notes' => 'Staff only: customer argued about price',
            'additional_note_color' => 'red',
        ])->assertCreated()->json('id');

        $bill = Bill::withoutGlobalScopes()->findOrFail($billId);

        $this->getJson('/api/bills/'.$billId)
            ->assertOk()
            ->assertJsonPath('internal_notes', 'Staff only: customer argued about price');

        $this->getJson('/api/bills/shared/'.$bill->share_token)
            ->assertOk()
            ->assertJsonMissingPath('internal_notes')
            ->assertJsonMissingPath('notes')
            ->assertJsonPath('additional_note', 'Staff only: customer argued about price')
            ->assertJsonPath('additional_note_color', 'red')
            ->assertJsonPath('bill_number', $bill->bill_number);
    }

    public function test_optional_employees_can_be_assigned_and_reported_by_month_or_year(): void
    {
        Sanctum::actingAs($this->garageUser());
        $mechanic = Employee::create([
            'name' => 'Sewu', 'nic' => '199012345678', 'phone' => '0771112222',
            'position' => 'Mechanic', 'base_salary' => 50000, 'fingerprint_id' => 'fp-1', 'active' => true,
        ]);
        $helper = Employee::create([
            'name' => 'Kamal', 'nic' => '199112345678', 'phone' => '0773334444',
            'position' => 'Helper', 'base_salary' => 35000, 'fingerprint_id' => 'fp-2', 'active' => true,
        ]);

        $thisYear = now()->year;
        $thisMonth = now()->month;

        $assigned = $this->postJson('/api/bills', [
            'number_plate' => 'CAB-1111',
            'admission_date' => now()->toDateString(),
            'employee_ids' => [$mechanic->id, $helper->id],
        ])->assertCreated()->json();

        $this->assertCount(2, $assigned['employees']);

        $unassigned = $this->postJson('/api/bills', [
            'number_plate' => 'CAB-2222',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json();
        $this->assertSame([], $unassigned['employees']);

        $this->putJson('/api/bills/'.$unassigned['id'].'/employees', [
            'employee_ids' => [$mechanic->id],
        ])->assertOk()->assertJsonCount(1, 'employees');

        $monthFrom = sprintf('%04d-%02d-01', $thisYear, $thisMonth);
        $monthTo = now()->endOfMonth()->toDateString();

        $monthReport = $this->getJson("/api/reports?from={$monthFrom}&to={$monthTo}&employee_id={$mechanic->id}")
            ->assertOk()
            ->json();

        $this->assertSame($mechanic->id, $monthReport['employee_jobs']['employee']['id']);
        $this->assertSame(2, $monthReport['employee_jobs']['count']);
        $this->assertNotEmpty($monthReport['employees']);

        $yearFrom = "{$thisYear}-01-01";
        $yearTo = "{$thisYear}-12-31";
        $yearReport = $this->getJson("/api/reports?from={$yearFrom}&to={$yearTo}&employee_id={$helper->id}")
            ->assertOk()
            ->json();

        $this->assertSame(1, $yearReport['employee_jobs']['count']);
        $this->assertSame($assigned['id'], $yearReport['employee_jobs']['jobs'][0]['id']);
    }

    private function garageUser(): User
    {
        $features = collect([
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports',
        ])->map(fn (string $key) => Feature::firstOrCreate(
            ['key' => $key],
            ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0],
        ));
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
