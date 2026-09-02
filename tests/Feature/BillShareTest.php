<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_a_shared_bill_by_token(): void
    {
        $bill = $this->createGarageBill();

        $this->getJson('/api/bills/shared/'.$bill->share_token)
            ->assertOk()
            ->assertJsonPath('bill_number', $bill->bill_number)
            ->assertJsonPath('customer.name', 'Nimal Perera')
            ->assertJsonPath('vehicle.number_plate', 'CAB-1234')
            ->assertJsonPath('tenant.business_name', $bill->tenant->business_name)
            ->assertJsonPath('items.0.hide_hours', true)
            ->assertJsonPath('items.0.quantity', null)
            ->assertJsonPath('items.0.unit_price', null)
            ->assertJsonPath('items.0.line_total', '6000.00');
    }

    public function test_shared_bill_link_still_works_when_sms_apps_append_punctuation(): void
    {
        $bill = $this->createGarageBill();

        $this->getJson('/api/bills/shared/'.$bill->share_token.'.')
            ->assertOk()
            ->assertJsonPath('bill_number', $bill->bill_number);
    }

    public function test_unknown_share_token_is_not_found(): void
    {
        $this->getJson('/api/bills/shared/doesnotexisttoken12')->assertNotFound();
    }

    public function test_staff_from_another_tenant_can_still_open_a_public_share_link(): void
    {
        $bill = $this->createGarageBill();
        Sanctum::actingAs($this->garageUser('staff'));

        $this->getJson('/api/bills/shared/'.$bill->share_token)
            ->assertOk()
            ->assertJsonPath('bill_number', $bill->bill_number);
    }

    public function test_sms_puts_the_bill_link_on_the_first_line(): void
    {
        $staff = $this->garageUser('staff', ['bill_sms']);
        Sanctum::actingAs($staff);
        $bill = $this->createGarageBill($staff);

        config([
            'app.frontend_url' => 'https://pos.example.com',
            'services.notify_lk.user_id' => '1',
            'services.notify_lk.api_key' => 'test-key',
            'services.notify_lk.sender_id' => 'TestSender',
            'services.notify_lk.endpoint' => 'https://app.notify.lk/api/v1/send',
        ]);

        Http::fake([
            'https://app.notify.lk/api/v1/send' => Http::response(['status' => 'success'], 200),
        ]);

        $this->postJson("/api/bills/{$bill->id}/send-sms")
            ->assertOk()
            ->assertJsonPath('share_token', $bill->share_token);

        Http::assertSent(function ($request) use ($bill) {
            $link = 'https://pos.example.com/share/bills/'.$bill->share_token;
            $message = (string) $request['message'];

            return str_starts_with($message, $link)
                && str_contains($message, 'quotation')
                && str_contains($message, 'CAB-1234');
        });
    }

    private function createGarageBill(?User $user = null): Bill
    {
        $actor = $user ?? $this->garageUser('staff');
        Sanctum::actingAs($actor);

        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-1234',
        ])->assertCreated()->json('id');

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Oil change',
            'quantity' => 1.5,
            'unit_price' => 4000,
        ])->assertCreated();

        return Bill::withoutGlobalScopes()->with('tenant')->findOrFail($billId);
    }

    /**
     * @param  list<string>  $extraFeatures
     */
    private function garageUser(string $role, array $extraFeatures = []): User
    {
        $keys = [
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports', 'bill_sms',
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
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);
        if ($role === 'staff') {
            $user->permissions()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['can_access' => true]]));
        }

        return $user;
    }
}
