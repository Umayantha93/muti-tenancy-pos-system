<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Employee;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GarageFloorModulesTest extends TestCase
{
    public function test_bay_calendar_blocks_double_booking_and_opens_a_job_card(): void
    {
        $user = $this->garageUser(['job_bookings', 'admit_vehicle', 'admit_repair', 'admit_service']);
        Sanctum::actingAs($user);

        $day = $this->getJson('/api/job-bookings?date='.now()->toDateString())->assertOk();
        $bayId = $day->json('bays.0.id');
        $this->assertNotNull($bayId);

        $tech = Employee::create([
            'name' => 'Ruwan', 'nic' => '199012345001', 'phone' => '0771000001',
            'position' => 'Technician', 'base_salary' => 50000, 'fingerprint_id' => 'fp-bay-1', 'active' => true,
        ]);

        $start = now()->toDateString().' 09:00:00';
        $end = now()->toDateString().' 10:00:00';
        $booking = $this->postJson('/api/job-bookings', [
            'bay_id' => $bayId,
            'starts_at' => $start,
            'ends_at' => $end,
            'number_plate' => 'CAA-9001',
            'customer_name' => 'Nimal',
            'customer_phone' => '0772000001',
            'employee_id' => $tech->id,
        ])->assertCreated()->json();

        $this->postJson('/api/job-bookings', [
            'bay_id' => $bayId,
            'starts_at' => $start,
            'ends_at' => $end,
            'number_plate' => 'CAA-9002',
        ])->assertUnprocessable()->assertJsonPath('message', 'This bay is already booked for that time.');

        $opened = $this->postJson('/api/job-bookings/'.$booking['id'].'/open-job')->assertOk();
        $this->assertNotNull($opened->json('bill.id'));
        $this->assertSame('CAA-9001', $opened->json('number_plate'));
        $this->assertSame('waiting', Bill::query()->find($opened->json('bill.id'))->floor_status);
    }

    public function test_job_board_moves_open_jobs_and_filters_technician(): void
    {
        $user = $this->garageUser(['job_board', 'admit_vehicle']);
        Sanctum::actingAs($user);
        $tech = Employee::create([
            'name' => 'Saman', 'nic' => '199012345002', 'phone' => '0771000002',
            'position' => 'Technician', 'base_salary' => 50000, 'fingerprint_id' => 'fp-board-1', 'active' => true,
        ]);

        $bill = $this->postJson('/api/bills', [
            'number_plate' => 'CAB-7001',
            'job_kind' => 'repair',
            'employee_ids' => [$tech->id],
        ])->assertCreated()->json();

        $this->assertSame('waiting', $bill['floor_status'] ?? 'waiting');

        $this->putJson('/api/bills/'.$bill['id'].'/floor-status', ['floor_status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('floor_status', 'in_progress');

        $board = $this->getJson('/api/job-board')->assertOk();
        $this->assertTrue(collect($board->json('columns.in_progress'))->contains(fn ($row) => $row['id'] === $bill['id']));

        $filtered = $this->getJson('/api/job-board?employee_id='.$tech->id)->assertOk();
        $this->assertTrue(collect($filtered->json('columns.in_progress'))->contains(fn ($row) => $row['id'] === $bill['id']));
    }

    public function test_service_reminder_lists_due_jobs_and_sends_sms(): void
    {
        $user = $this->garageUser(['service_reminders', 'bill_sms', 'admit_vehicle', 'admit_service']);
        Sanctum::actingAs($user);

        config([
            'services.notify_lk.user_id' => '1',
            'services.notify_lk.api_key' => 'test-key',
            'services.notify_lk.sender_id' => 'TestSender',
            'services.notify_lk.endpoint' => 'https://app.notify.lk/api/v1/send',
        ]);
        Http::fake(['https://app.notify.lk/api/v1/send' => Http::response(['status' => 'success'], 200)]);

        $bill = $this->postJson('/api/bills', [
            'number_plate' => 'CAC-8001',
            'customer_name' => 'Kamal',
            'customer_phone' => '0773000003',
            'job_kind' => 'service',
            'mileage' => 40000,
            'next_service_mileage' => 45000,
            'next_service_due_on' => now()->addDays(5)->toDateString(),
        ])->assertCreated()->json();

        $list = $this->getJson('/api/service-reminders?days=14')->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains(fn ($row) => $row['id'] === $bill['id']));

        $this->postJson('/api/service-reminders/'.$bill['id'].'/send')
            ->assertOk()
            ->assertJsonPath('message', 'Next-service reminder sent by SMS.');

        $this->assertNotNull(Bill::query()->find($bill['id'])->service_reminder_sent_at);
    }

    public function test_floor_modules_are_forbidden_until_enabled(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/job-bookings')->assertForbidden();
        $this->getJson('/api/job-board')->assertForbidden();
        $this->getJson('/api/service-reminders')->assertForbidden();
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
