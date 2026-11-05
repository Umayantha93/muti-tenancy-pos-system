<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LineVatTest extends TestCase
{
    public function test_line_vat_is_optional_until_the_super_admin_enables_it(): void
    {
        $this->assertContains('line_vat', BusinessTypes::optionalFeatures(BusinessTypes::GARAGE));
        $this->assertNotContains('line_vat', BusinessTypes::defaults(BusinessTypes::GARAGE));

        $owner = $this->userWith(['admit_vehicle', 'admit_repair', 'billing']);
        Sanctum::actingAs($owner);
        $billId = $this->bill();
        $itemId = $this->labor($billId, 1000);

        $this->putJson("/api/bills/{$billId}/items/{$itemId}", ['line_vat' => 100])
            ->assertForbidden();
    }

    public function test_vat_amount_comes_out_of_the_rate_and_into_the_bill_total(): void
    {
        $owner = $this->userWith(['admit_vehicle', 'admit_repair', 'billing', 'line_vat']);
        Sanctum::actingAs($owner);
        $billId = $this->bill();
        $itemId = $this->labor($billId, 1000);

        $this->putJson("/api/bills/{$billId}/items/{$itemId}", ['line_vat' => 100])
            ->assertOk()
            ->assertJsonPath('item.line_total', '900.00')
            ->assertJsonPath('item.unit_price', '1000.00')
            ->assertJsonPath('bill.vat_amount', '100.00')
            ->assertJsonPath('bill.balance_due', '1000.00');

        $this->putJson("/api/bills/{$billId}/items/{$itemId}", ['line_vat' => 1001])
            ->assertStatus(422);
    }

    public function test_owe_in_bills_can_still_set_line_vat(): void
    {
        $owner = $this->userWith(['admit_vehicle', 'admit_repair', 'billing', 'line_vat']);
        Sanctum::actingAs($owner);
        $billId = $this->bill();
        $itemId = $this->labor($billId, 2000);
        $this->postJson("/api/bills/{$billId}/owe-in", [
            'due_date' => now()->addDay()->toDateString(),
        ])->assertOk();

        $this->putJson("/api/bills/{$billId}/items/{$itemId}", ['line_vat' => 360])
            ->assertOk()
            ->assertJsonPath('item.line_total', '1640.00')
            ->assertJsonPath('bill.vat_amount', '360.00')
            ->assertJsonPath('bill.balance_due', '2000.00')
            ->assertJsonPath('bill.status', 'owe_in');
    }

    private function bill(): int
    {
        return $this->postJson('/api/bills', [
            'number_plate' => 'VAT-'.fake()->unique()->numerify('####'),
            'job_kind' => 'repair',
        ])->assertCreated()->json('id');
    }

    private function labor(int $billId, float $price): int
    {
        return $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Brake work',
            'quantity' => 1,
            'unit_price' => $price,
        ])->assertCreated()->json('item.id');
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
