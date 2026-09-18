<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    public function test_owner_can_create_customer_see_outstanding_and_print_statement(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/customers', [
            'name' => 'Kamal Silva',
            'phone' => '0778889999',
            'address' => 'Kandy',
            'sms_opt_in' => true,
        ])->assertCreated()
            ->assertJsonPath('name', 'Kamal Silva')
            ->assertJsonPath('sms_opt_in', true);

        $bill = $this->postJson('/api/bills/instant', [
            'customer_name' => 'Kamal Silva',
            'customer_phone' => '0778889999',
        ])->assertCreated()->json();

        $this->postJson('/api/bills/'.$bill['id'].'/items', [
            'type' => 'labor',
            'description' => 'Clutch',
            'quantity' => 1,
            'unit_price' => 4000,
        ])->assertCreated();

        $this->getJson('/api/customers?search=Kamal')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created->json('id'))
            ->assertJsonPath('data.0.last_bill.bill_number', $bill['bill_number']);

        $this->assertGreaterThan(0, (float) $this->getJson('/api/customers?search=Kamal')->json('data.0.outstanding_balance'));

        $this->getJson('/api/customers/'.$created->json('id').'/statement')
            ->assertOk()
            ->assertJsonPath('name', 'Kamal Silva');

        $this->putJson('/api/customers/'.$created->json('id'), ['sms_opt_in' => false])
            ->assertOk()
            ->assertJsonPath('sms_opt_in', false);
    }

    private function owner(): User
    {
        $features = collect(['customers', 'billing', 'admit_vehicle'])
            ->map(fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0]));
        $tenant = Tenant::create([
            'business_name' => 'Directory Garage',
            'business_type' => 'garage',
            'owner_name' => 'Owner',
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
