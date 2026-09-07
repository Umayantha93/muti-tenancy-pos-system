<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Feature;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GarageAdvancedOpsTest extends TestCase
{
    public function test_garage_restock_attaches_walk_in_supplier_and_supplier_file_shows_history(): void
    {
        $user = $this->garageUser(['suppliers', 'parts_inventory', 'balance_sheet']);
        Sanctum::actingAs($user);
        $walkIn = Supplier::query()->where('is_system', true)->first();
        $this->assertNotNull($walkIn);

        $part = Part::create([
            'name' => 'Oil 5W-30', 'brand' => 'Castrol', 'type' => 'oil',
            'price' => 7200, 'cost_price' => 6200, 'stock_qty' => 2,
        ]);
        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 12,
            'unit_cost' => 6200,
            'payment_status' => 'credit',
            'due_date' => now()->addMonth()->toDateString(),
        ])->assertOk();

        $this->getJson("/api/suppliers/{$walkIn->id}")
            ->assertOk()
            ->assertJsonPath('open_credit', 74400)
            ->assertJsonPath('purchase_count', 1)
            ->assertJsonPath('parts_bought.0.name', 'Oil 5W-30');
    }

    public function test_repair_note_hides_share_amounts_and_clears_on_payment(): void
    {
        $user = $this->garageUser(['billing', 'admit_vehicle']);
        Sanctum::actingAs($user);
        $bill = $this->openJob($user);

        $this->putJson("/api/bills/{$bill->id}", ['hide_amounts' => true])
            ->assertOk()
            ->assertJsonPath('hide_amounts', true);

        $this->getJson('/api/bills/shared/'.$bill->share_token)
            ->assertOk()
            ->assertJsonPath('hide_amounts', true)
            ->assertJsonPath('subtotal', null)
            ->assertJsonPath('items.0.line_total', null)
            ->assertJsonPath('items.0.quantity', '1.00');

        $this->postJson("/api/bills/{$bill->id}/payments", [
            'amount' => 1000,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertFalse((bool) $bill->fresh()->hide_amounts);
        $this->getJson('/api/bills/shared/'.$bill->fresh()->share_token)
            ->assertOk()
            ->assertJsonPath('hide_amounts', false)
            ->assertJsonPath('items.0.line_total', '6000.00');
    }

    public function test_bill_sms_sends_owner_copy_when_flag_on(): void
    {
        $user = $this->garageUser(['bill_sms', 'owner_bill_sms']);
        $user->tenant->update([
            'owner_phone' => '0779998888',
            'owner_phones' => [['label' => 'Primary', 'number' => '0779998888']],
        ]);
        Sanctum::actingAs($user);
        $bill = $this->openJob($user);

        config([
            'app.frontend_url' => 'https://pos.example.com',
            'services.notify_lk.user_id' => '1',
            'services.notify_lk.api_key' => 'test-key',
            'services.notify_lk.sender_id' => 'TestSender',
            'services.notify_lk.endpoint' => 'https://app.notify.lk/api/v1/send',
        ]);
        Http::fake(['https://app.notify.lk/api/v1/send' => Http::response(['status' => 'success'], 200)]);

        $this->postJson("/api/bills/{$bill->id}/send-sms")
            ->assertOk()
            ->assertJsonPath('owner_sent', 1);

        Http::assertSentCount(2);
    }

    public function test_service_ops_report_counts_sold_lines_and_full_service_inclusions_separately(): void
    {
        $user = $this->garageUser(['service_ops_report', 'billing', 'admit_vehicle']);
        Sanctum::actingAs($user);
        ServiceAddon::seedDefaultsFor((int) $user->tenant_id);
        $grease = ServiceAddon::query()->where('name', 'Nipple grease')->first();
        $full = ServiceAddon::query()->where('is_full_service', true)->first();
        $this->assertNotNull($grease);
        $this->assertNotNull($full);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-9001',
            'job_kind' => 'service',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $full->id, 'quantity' => 1])->assertCreated();
        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $grease->id, 'quantity' => 2])->assertCreated();

        $report = $this->getJson('/api/reports/service-ops')->assertOk()->json();
        $greaseRow = collect($report['rows'])->firstWhere('service_addon_id', $grease->id);
        $fullRow = collect($report['rows'])->firstWhere('service_addon_id', $full->id);
        $this->assertEquals(2, $greaseRow['sold_qty']);
        $this->assertEquals(1, $greaseRow['inside_full_service']);
        $this->assertEquals(1, $fullRow['sold_qty']);
        $this->assertNull($fullRow['inside_full_service']);
        $this->assertEquals(1, $report['jobs']);
    }

    private function openJob(User $user): Bill
    {
        Sanctum::actingAs($user);
        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1234',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'part',
            'description' => 'Oil filter',
            'quantity' => 1,
            'unit_price' => 6000,
        ])->assertCreated();

        return Bill::withoutGlobalScopes()->with('tenant')->findOrFail($billId);
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
