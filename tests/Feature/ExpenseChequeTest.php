<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCheque;
use App\Models\ExpenseSettlement;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseChequeTest extends TestCase
{
    public function test_issue_cheque_does_not_hit_monthly_expenses_until_cleared(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $expenseId = $this->createCreditRestock();

        $chequeDate = now()->addDays(10)->toDateString();
        $this->postJson("/api/expenses/{$expenseId}/cheques", [
            'amount' => 100000,
            'cheque_date' => $chequeDate,
            'cheque_number' => 'CHQ-100',
        ])->assertCreated()
            ->assertJsonPath('cheque.status', 'pending')
            ->assertJsonPath('expense.pending_cheque_total', 100000)
            ->assertJsonPath('expense.available_to_pay', 210000);

        $sheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertEquals(0, $sheet['expenses']);
        $this->assertEquals(310000, $sheet['inventory_payables']['payables_total']);
        $this->assertEquals(100000, $sheet['inventory_payables']['items'][0]['pending_cheque_total']);
        $this->assertEquals(210000, $sheet['inventory_payables']['items'][0]['available_to_pay']);

        $this->postJson("/api/expenses/{$expenseId}/settle", ['amount' => 250000])
            ->assertUnprocessable();

        $chequeId = ExpenseCheque::query()->first()->id;
        $this->postJson("/api/expenses/cheques/{$chequeId}/clear", [
            'cleared_on' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('cheque.status', 'cleared')
            ->assertJsonPath('expense.remaining', 210000);

        $this->assertEquals(1, ExpenseSettlement::query()->count());
        $settled = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertEquals(100000, $settled['expenses']);
        $this->assertEquals(210000, $settled['inventory_payables']['payables_total']);
    }

    public function test_bounce_frees_available_balance(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $expenseId = $this->createCreditRestock();

        $chequeId = $this->postJson("/api/expenses/{$expenseId}/cheques", [
            'amount' => 50000,
            'cheque_date' => now()->toDateString(),
        ])->assertCreated()->json('cheque.id');

        $this->postJson("/api/expenses/cheques/{$chequeId}/bounce")->assertOk()
            ->assertJsonPath('cheque.status', 'bounced')
            ->assertJsonPath('expense.available_to_pay', 310000)
            ->assertJsonPath('expense.pending_cheque_total', 0);

        $this->assertEquals(0, ExpenseSettlement::query()->count());
    }

    public function test_due_cheques_endpoint_lists_pending_on_or_before_today(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $expenseId = $this->createCreditRestock();

        $this->postJson("/api/expenses/{$expenseId}/cheques", [
            'amount' => 20000,
            'cheque_date' => now()->subDay()->toDateString(),
            'cheque_number' => 'DUE-1',
        ])->assertCreated();
        $this->postJson("/api/expenses/{$expenseId}/cheques", [
            'amount' => 15000,
            'cheque_date' => now()->addDays(5)->toDateString(),
            'cheque_number' => 'FUTURE-1',
        ])->assertCreated();

        $due = $this->getJson('/api/expenses/cheques/due')->assertOk()->json();
        $this->assertEquals(1, $due['count']);
        $this->assertEquals('DUE-1', $due['items'][0]['cheque_number']);
        $this->assertEquals($expenseId, $due['items'][0]['expense_id']);
    }

    public function test_cannot_over_issue_cheques(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);
        $expenseId = $this->createCreditRestock();

        $this->postJson("/api/expenses/{$expenseId}/cheques", [
            'amount' => 310000,
            'cheque_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/expenses/{$expenseId}/cheques", [
            'amount' => 1,
            'cheque_date' => now()->toDateString(),
        ])->assertUnprocessable();
    }

    private function createCreditRestock(): int
    {
        $part = Part::create([
            'name' => 'Brake Pads', 'brand' => 'Nissin', 'type' => 'brake',
            'price' => 7800, 'cost_price' => 6200, 'stock_qty' => 2,
        ]);

        $this->postJson("/api/parts/{$part->id}/restock", [
            'quantity' => 50,
            'unit_cost' => 6200,
            'payment_status' => 'credit',
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertOk();

        return (int) Expense::credit()->first()->id;
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
