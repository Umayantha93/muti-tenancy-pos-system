<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JobCustomerReassignTest extends TestCase
{
    public function test_picking_another_company_moves_only_this_job_and_keeps_the_shared_name(): void
    {
        Sanctum::actingAs($this->garageUser());

        $first = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0771111111',
            'customer_address' => 'Colombo',
            'number_plate' => 'AL-1001',
        ])->assertCreated()->json();
        $second = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0771111111',
            'number_plate' => 'AL-1002',
        ])->assertCreated()->json();

        $aku = Customer::create([
            'name' => 'AKU Industries',
            'phone' => '0772222222',
            'address' => 'Kandy',
        ]);

        $this->putJson('/api/bills/'.$first['id'], [
            'customer_id' => $aku->id,
            'customer_name' => 'AKU Industries',
            'customer_phone' => '0772222222',
            'customer_address' => 'Kandy',
        ])->assertOk()
            ->assertJsonPath('customer.id', $aku->id)
            ->assertJsonPath('customer.name', 'AKU Industries')
            ->assertJsonPath('customer.phone', '0772222222')
            ->assertJsonPath('vehicle.customer_id', $aku->id);

        $alutek = Customer::query()->findOrFail($first['customer']['id']);
        $this->assertSame('Alutek Group', $alutek->name);
        $this->assertSame('0771111111', $alutek->phone);
        $this->assertSame($alutek->id, Bill::query()->findOrFail($second['id'])->customer_id);
        $this->assertSame($alutek->id, Vehicle::query()->findOrFail($second['vehicle']['id'])->customer_id);
    }

    public function test_typing_an_existing_company_name_does_not_overwrite_that_companys_phone(): void
    {
        Sanctum::actingAs($this->garageUser());

        $job = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0771111111',
            'number_plate' => 'AL-2001',
        ])->assertCreated()->json();
        $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0771111111',
            'number_plate' => 'AL-2002',
        ])->assertCreated();

        $aku = Customer::create([
            'name' => 'AKU Industries',
            'phone' => '0772222222',
            'address' => 'Kandy',
        ]);

        $this->putJson('/api/bills/'.$job['id'], [
            'customer_name' => 'AKU Industries',
            'customer_phone' => '0771111111',
        ])->assertOk()
            ->assertJsonPath('customer.id', $aku->id)
            ->assertJsonPath('customer.phone', '0772222222');

        $this->assertSame('Alutek Group', Customer::query()->findOrFail($job['customer']['id'])->name);
        $this->assertSame('0772222222', $aku->fresh()->phone);
    }

    public function test_a_new_company_name_on_a_shared_customer_opens_a_separate_customer(): void
    {
        Sanctum::actingAs($this->garageUser());

        $job = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0771111111',
            'number_plate' => 'AL-3001',
        ])->assertCreated()->json();
        $other = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0771111111',
            'number_plate' => 'AL-3002',
        ])->assertCreated()->json();

        $moved = $this->putJson('/api/bills/'.$job['id'], [
            'customer_name' => 'AKS Industries',
            'customer_phone' => '0773333333',
            'customer_address' => 'Galle',
        ])->assertOk()->json();

        $this->assertSame('AKS Industries', $moved['customer']['name']);
        $this->assertSame('0773333333', $moved['customer']['phone']);
        $this->assertNotSame($job['customer']['id'], $moved['customer']['id']);
        $this->assertSame('Alutek Group', Customer::query()->findOrFail($job['customer']['id'])->name);
        $this->assertSame($job['customer']['id'], Bill::query()->findOrFail($other['id'])->customer_id);
        $this->assertSame($moved['customer']['id'], Vehicle::query()->findOrFail($job['vehicle']['id'])->customer_id);
    }

    public function test_a_lone_customer_can_still_have_a_name_correction(): void
    {
        Sanctum::actingAs($this->garageUser());

        $job = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Grup',
            'customer_phone' => '0774444444',
            'number_plate' => 'AL-4001',
        ])->assertCreated()->json();

        $this->putJson('/api/bills/'.$job['id'], [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0774444444',
        ])->assertOk()
            ->assertJsonPath('customer.id', $job['customer']['id'])
            ->assertJsonPath('customer.name', 'Alutek Group');
    }

    public function test_customer_can_change_on_owe_in_and_paid_jobs_but_not_closed_ones(): void
    {
        Sanctum::actingAs($this->garageUser());

        $oweIn = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0775555555',
            'number_plate' => 'AL-5001',
        ])->assertCreated()->json();
        $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0775555555',
            'number_plate' => 'AL-5002',
        ])->assertCreated();
        $this->postJson('/api/bills/'.$oweIn['id'].'/items', [
            'type' => 'labor', 'description' => 'Service', 'quantity' => 1, 'unit_price' => 5000,
        ])->assertCreated();
        $this->postJson('/api/bills/'.$oweIn['id'].'/owe-in', [
            'due_date' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $aku = Customer::create([
            'name' => 'AKU Industries',
            'phone' => '0776666666',
            'address' => 'Kandy',
        ]);
        $this->putJson('/api/bills/'.$oweIn['id'], [
            'customer_id' => $aku->id,
            'customer_name' => 'AKU Industries',
            'customer_phone' => '0776666666',
        ])->assertOk()->assertJsonPath('customer.id', $aku->id);

        $paid = $this->postJson('/api/bills', [
            'customer_name' => 'Alutek Group',
            'customer_phone' => '0775555555',
            'number_plate' => 'AL-5003',
        ])->assertCreated()->json();
        $this->postJson('/api/bills/'.$paid['id'].'/items', [
            'type' => 'labor', 'description' => 'Service', 'quantity' => 1, 'unit_price' => 1500,
        ])->assertCreated();
        $this->postJson('/api/bills/'.$paid['id'].'/payments', [
            'amount' => 1500, 'method' => 'cash',
        ])->assertCreated()->assertJsonPath('bill.status', 'paid');

        $aks = Customer::create([
            'name' => 'AKS Industries',
            'phone' => '0777777777',
        ]);
        $this->putJson('/api/bills/'.$paid['id'], [
            'customer_id' => $aks->id,
            'customer_name' => 'AKS Industries',
            'customer_phone' => '0777777777',
        ])->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('customer.id', $aks->id);

        $this->postJson('/api/bills/'.$paid['id'].'/close')->assertOk();
        $this->putJson('/api/bills/'.$paid['id'], [
            'customer_id' => $aku->id,
            'customer_name' => 'AKU Industries',
            'customer_phone' => '0776666666',
        ])->assertStatus(422)->assertJsonPath('message', 'Closed bills cannot be edited.');
    }

    private function garageUser(): User
    {
        $features = collect([
            'admit_vehicle', 'customers', 'billing',
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
