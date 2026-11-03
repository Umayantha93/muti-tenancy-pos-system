<?php

namespace Tests\Feature;

use App\Models\BillPayment;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OweInRegisterTest extends TestCase
{
    public function test_register_is_closed_until_super_admin_enables_it(): void
    {
        [, $owner] = $this->garage();
        Sanctum::actingAs($owner);

        $this->getJson('/api/finance/owe-in')->assertForbidden();
    }

    public function test_register_lists_owe_in_customers_and_filters_the_total_by_due_date(): void
    {
        [$tenant, $owner] = $this->garage();
        $this->enable($tenant, 'owe_in_register');
        Sanctum::actingAs($owner);

        $soon = now()->addDays(3)->toDateString();
        $later = now()->addDays(40)->toDateString();
        $this->oweIn('Kamal Silva', '0771111001', 'WP-CAB-1001', 4000, $soon);
        $this->oweIn('Nimal Fernando', '0771111002', 'WP-CAB-1002', 2500, $later);

        $all = $this->getJson('/api/finance/owe-in')->assertOk();
        $this->assertEquals(6500, $all->json('receivables_total'));
        $this->assertSame(2, $all->json('count'));
        $names = collect($all->json('data'))->pluck('customer_name')->all();
        $this->assertContains('Kamal Silva', $names);
        $this->assertContains('Nimal Fernando', $names);
        $kamal = collect($all->json('data'))->firstWhere('customer_name', 'Kamal Silva');
        $this->assertSame('0771111001', $kamal['phone']);
        $this->assertSame('WP-CAB-1001', $kamal['vehicle_number']);
        $this->assertEquals(4000, $kamal['amount']);

        $filtered = $this->getJson('/api/finance/owe-in?date_from='.$soon.'&date_to='.$soon)->assertOk();
        $this->assertEquals(4000, $filtered->json('receivables_total'));
        $this->assertSame(1, $filtered->json('count'));
        $this->assertSame('Kamal Silva', $filtered->json('data.0.customer_name'));
    }

    public function test_pdf_is_a_download_and_other_trades_cannot_open_it(): void
    {
        [$tenant, $owner] = $this->garage();
        $this->enable($tenant, 'owe_in_register');
        Sanctum::actingAs($owner);
        $this->oweIn('Kamal Silva', '0771111001', 'WP-CAB-1001', 4000, now()->addDays(3)->toDateString());

        $this->get('/api/finance/owe-in/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_other_trades_cannot_open_the_register(): void
    {
        [$shop, $shopOwner] = $this->garage(BusinessTypes::CLOTHING);
        $this->enable($shop, 'owe_in_register');
        $this->enable($shop, 'balance_sheet');
        Sanctum::actingAs($shopOwner);

        $this->getJson('/api/finance/owe-in')->assertNotFound();
    }

    public function test_owe_in_payment_keeps_the_day_the_customer_actually_paid(): void
    {
        [, $owner] = $this->garage();
        Sanctum::actingAs($owner);
        $billId = $this->oweIn('Kamal Silva', '0771111001', 'WP-CAB-1001', 4000, now()->addDays(3)->toDateString());
        $paidOn = now()->subDays(4)->toDateString();

        $this->postJson("/api/bills/{$billId}/payments", [
            'amount' => 1500,
            'method' => 'cash',
            'paid_at' => $paidOn,
        ])->assertCreated();

        $this->assertSame($paidOn, BillPayment::query()->where('bill_id', $billId)->firstOrFail()->paid_at->toDateString());

        $this->postJson("/api/bills/{$billId}/payments", [
            'amount' => 100,
            'method' => 'cash',
            'paid_at' => now()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function garage(string $type = BusinessTypes::GARAGE): array
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $tenant = Tenant::create([
            'business_name' => 'Bay Test',
            'business_type' => $type,
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $features = Feature::whereIn('key', BusinessTypes::defaults($type))->get();
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);

        return [$tenant, $user];
    }

    private function enable(Tenant $tenant, string $key): void
    {
        $feature = Feature::query()->where('key', $key)->firstOrFail();
        $tenant->features()->syncWithoutDetaching([$feature->id => ['is_enabled' => true]]);
    }

    private function oweIn(string $name, string $phone, string $plate, float $amount, string $due): int
    {
        $billId = $this->postJson('/api/bills', [
            'customer_name' => $name,
            'customer_phone' => $phone,
            'number_plate' => $plate,
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => $amount,
        ])->assertCreated();

        $this->postJson("/api/bills/{$billId}/owe-in", ['due_date' => $due])->assertOk();

        return $billId;
    }
}
