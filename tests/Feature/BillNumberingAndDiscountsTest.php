<?php

namespace Tests\Feature;

use App\Models\DiscountType;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillNumberingAndDiscountsTest extends TestCase
{
    public function test_admit_accepts_past_admission_date_and_job_kind(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $bill = $this->postJson('/api/bills', [
            'customer_name' => 'Late Entry',
            'customer_phone' => '0771111111',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'admission_date' => '2026-01-15',
            'job_kind' => 'service',
        ])->assertCreated()->json();

        $this->assertSame('2026-01-15', $bill['admission_date']);
        $this->assertSame('service', $bill['job_kind']);
    }

    public function test_locked_prefix_issues_sequential_short_numbers_and_sa_can_reset(): void
    {
        $owner = $this->garageUser('business_owner');
        Sanctum::actingAs($owner);
        $tenant = $owner->tenant;

        $this->post('/api/tenant/profile', [
            'business_name' => $tenant->business_name,
            'owner_name' => $tenant->owner_name,
            'owner_phone' => $tenant->owner_phone,
            'owner_email' => $tenant->owner_email,
            'bill_prefix' => 'B06',
            'lock_bill_numbers' => '1',
        ])->assertOk();

        $tenant->refresh();
        $this->assertNotNull($tenant->bill_number_locked_at);
        $this->assertSame('B06', $tenant->bill_prefix);

        $first = $this->postJson('/api/bills', [
            'customer_name' => 'A',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'repair',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json('bill_number');

        $second = $this->postJson('/api/bills', [
            'customer_name' => 'B',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'repair',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json('bill_number');

        $this->assertSame('B06-'.str_pad((string) $tenant->id, 2, '0', STR_PAD_LEFT).'-0001', $first);
        $this->assertSame('B06-'.str_pad((string) $tenant->id, 2, '0', STR_PAD_LEFT).'-0002', $second);

        $admin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/super-admin/tenants/{$tenant->id}/reset-bill-numbers", [
            'bill_sequence' => 0,
        ])->assertOk();

        $tenant->refresh();
        $this->assertNull($tenant->bill_number_locked_at);
        $this->assertSame(0, (int) $tenant->bill_sequence);
    }

    public function test_unlocked_tenant_keeps_legacy_bill_number_format(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));

        $number = $this->postJson('/api/bills', [
            'customer_name' => 'Walk',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'repair',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json('bill_number');

        $this->assertDoesNotMatchRegularExpression('/^[A-Z0-9]+-\d{4}$/', $number);
        $this->assertMatchesRegularExpression('/JOB-\d{8}-[A-Z0-9]{6}/i', $number);
    }

    public function test_discount_type_amount_and_percent_create_discount_lines(): void
    {
        Sanctum::actingAs($this->garageUser('business_owner'));

        $types = $this->getJson('/api/discount-types')->assertOk()->json();
        $this->assertNotEmpty($types);
        $percent = collect($types)->firstWhere('mode', 'percent');
        $amount = collect($types)->firstWhere('mode', 'amount');
        $this->assertNotNull($percent);
        $this->assertNotNull($amount);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Disc',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'repair',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Labor',
            'unit_price' => 10000,
            'quantity' => 1,
        ])->assertCreated();

        $pctLine = $this->postJson("/api/bills/{$billId}/items", [
            'discount_type_id' => $percent['id'],
        ])->assertCreated()->json('item');

        $expectedPct = round(10000 * ((float) $percent['value'] / 100), 2);
        $this->assertSame('discount', $pctLine['type']);
        $this->assertEquals($expectedPct, (float) $pctLine['line_total']);

        $amtLine = $this->postJson("/api/bills/{$billId}/items", [
            'discount_type_id' => $amount['id'],
        ])->assertCreated()->json('item');

        $this->assertEquals((float) $amount['value'], (float) $amtLine['line_total']);
    }

    public function test_inventory_line_discount_percent_reduces_line_total(): void
    {
        Sanctum::actingAs($this->garageUser('staff'));
        $part = Part::create([
            'name' => 'Pad',
            'brand' => 'Akebono',
            'type' => 'brake',
            'price' => 1000,
            'cost_price' => 500,
            'stock_qty' => 20,
        ]);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Pct',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'repair',
            'admission_date' => now()->toDateString(),
        ])->assertCreated()->json('id');

        $item = $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part',
            'part_id' => $part->id,
            'quantity' => 2,
            'discount_percent' => 10,
        ])->assertCreated()->json('item');

        $this->assertEquals(10, (float) $item['discount_percent']);
        $this->assertEquals(1800.0, (float) $item['line_total']);
        $this->assertSame(18, $part->fresh()->stock_qty);
    }

    private function garageUser(string $role): User
    {
        $keys = [
            'admit_vehicle', 'admit_repair', 'admit_service', 'customers', 'billing',
            'parts_inventory', 'employees_management', 'reports',
        ];
        $features = collect($keys)->map(
            fn (string $key) => Feature::firstOrCreate(
                ['key' => $key],
                ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0]
            )
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
        DiscountType::seedDefaultsFor((int) $tenant->id, 'garage');
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);
        if ($role === 'staff') {
            $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));
        }

        return $user->load('tenant');
    }
}
