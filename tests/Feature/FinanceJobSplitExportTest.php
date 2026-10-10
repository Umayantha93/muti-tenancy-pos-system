<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Feature;
use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceJobSplitExportTest extends TestCase
{
    public function test_expenses_need_repair_or_service_and_finance_splits_the_totals(): void
    {
        Sanctum::actingAs($this->garageUser(['expense_job_split']));
        $today = now()->toDateString();

        $this->postJson('/api/expenses', [
            'category' => 'maintenance', 'description' => 'Lift oil', 'amount' => 1000, 'expense_date' => $today,
        ])->assertStatus(422)->assertJsonValidationErrors('job_kind');

        $this->postJson('/api/expenses', [
            'category' => 'maintenance', 'description' => 'Lift oil', 'amount' => 1000, 'expense_date' => $today, 'job_kind' => 'repair',
        ])->assertCreated()->assertJsonPath('job_kind', 'repair');
        $this->postJson('/api/expenses', [
            'category' => 'utilities', 'description' => 'Wash bay water', 'amount' => 500, 'expense_date' => $today, 'job_kind' => 'service',
        ])->assertCreated();

        $sheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertEquals(1000, $sheet['expense_split']['repair']);
        $this->assertEquals(500, $sheet['expense_split']['service']);
        $row = collect($sheet['accounts'])->firstWhere('description', 'Lift oil');
        $this->assertSame('Repair', $row['details']);
        $this->assertNull($row['vehicle']);
    }

    public function test_inventory_purchases_are_never_split_into_repair_or_service(): void
    {
        Sanctum::actingAs($this->garageUser(['expense_job_split']));
        $today = now()->toDateString();

        $this->postJson('/api/expenses', [
            'category' => 'inventory', 'description' => 'Oil drums', 'amount' => 3000, 'expense_date' => $today,
        ])->assertCreated()->assertJsonPath('job_kind', null);
        $taggedId = $this->postJson('/api/expenses', [
            'category' => 'inventory', 'description' => 'Brake pads', 'amount' => 2000, 'expense_date' => $today, 'job_kind' => 'repair',
        ])->assertCreated()->assertJsonPath('job_kind', null)->json('id');
        Expense::query()->whereKey($taggedId)->update(['job_kind' => 'service']);

        $sheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertEquals(5000, $sheet['expense_split']['inventory']);
        $this->assertEquals(0, $sheet['expense_split']['repair']);
        $this->assertEquals(0, $sheet['expense_split']['service']);
        $row = collect($sheet['accounts'])->firstWhere('description', 'Brake pads');
        $this->assertStringNotContainsString('Service', (string) $row['details']);
    }

    public function test_without_the_feature_job_kind_is_optional_and_no_split_or_export(): void
    {
        Sanctum::actingAs($this->garageUser([]));

        $this->postJson('/api/expenses', [
            'category' => 'rent', 'description' => 'Rent', 'amount' => 20000, 'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $sheet = $this->getJson('/api/balance-sheet?month='.now()->month.'&year='.now()->year)->assertOk()->json();
        $this->assertArrayNotHasKey('expense_split', $sheet);

        $this->get('/api/balance-sheet/export?month='.now()->month.'&year='.now()->year.'&format=xlsx&type=full')
            ->assertForbidden();
    }

    public function test_bill_rows_show_vehicle_and_report_downloads_as_excel_and_pdf(): void
    {
        Sanctum::actingAs($this->garageUser(['finance_report_export']));
        $wash = ServiceAddon::create(['name' => 'Body wash', 'price' => 0, 'sort_order' => 10, 'active' => true]);
        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal', 'customer_phone' => '0771234567',
            'number_plate' => 'CAB-7001', 'job_kind' => 'service',
        ])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$billId}/items", ['type' => 'service_addon', 'service_addon_id' => $wash->id, 'quantity' => 1, 'unit_price' => 1200])
            ->assertCreated();
        $this->postJson("/api/bills/{$billId}/payments", ['amount' => 1200, 'method' => 'cash'])->assertCreated();

        $query = 'month='.now()->month.'&year='.now()->year;
        $sheet = $this->getJson("/api/balance-sheet?{$query}")->assertOk()->json();
        $payment = collect($sheet['accounts'])->firstWhere('category', 'Sales Revenue');
        $this->assertSame('CAB-7001', $payment['vehicle']);
        $this->assertStringContainsString('Customer: Nimal', $payment['details']);
        $this->assertStringContainsString('Service job', $payment['details']);

        $excel = $this->get("/api/balance-sheet/export?{$query}&format=xlsx&type=table")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $excel->headers->get('Content-Type'));
        $monthPath = tempnam(sys_get_temp_dir(), 'fin');
        file_put_contents($monthPath, $excel->streamedContent());
        $monthSheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($monthPath)->getActiveSheet()->toArray();
        @unlink($monthPath);
        $labels = collect($monthSheet)->map(fn ($row) => (string) ($row[0] ?? ''))->filter()->values();
        $all = $labels->search('All sales');
        $bank = $labels->search('Bank sales');
        $credit = $labels->search('Credit sales');
        $cash = $labels->search('Cash sales');
        $this->assertNotFalse($all);
        $this->assertGreaterThan($all, $bank);
        $this->assertGreaterThan($bank, $credit);
        $this->assertGreaterThan($credit, $cash);
        $this->assertSame('Cash sales', $labels->last());

        $today = now()->toDateString();
        $dayExport = $this->get("/api/balance-sheet/export?{$query}&date={$today}&format=xlsx&type=full")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'fin');
        file_put_contents($path, $dayExport->streamedContent());
        $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        @unlink($path);
        $flat = collect($loaded->getActiveSheet()->toArray())->flatten()->filter()->map(fn ($cell) => (string) $cell);
        $this->assertTrue($flat->contains('All sales'));
        $this->assertTrue($flat->contains('Bank sales'));
        $this->assertTrue($flat->contains('Credit sales'));
        $this->assertTrue($flat->contains('Cash sales'));
        $detail = collect($loaded->getSheet(1)->toArray())->map(fn ($row) => (string) ($row[0] ?? ''));
        $this->assertSame('Cash sales', $detail->filter()->last());

        $pdf = $this->get("/api/balance-sheet/export?{$query}&format=pdf&type=table")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->get("/api/balance-sheet/export?{$query}&format=pdf&type=full")->assertOk();
    }

    public function test_logged_out_download_gets_401_instead_of_login_redirect_crash(): void
    {
        $this->get('/api/balance-sheet/export?month=9&year=2026&format=pdf&type=full')
            ->assertUnauthorized();
    }

    private function garageUser(array $extra): User
    {
        $keys = [
            'admit_vehicle', 'admit_service', 'customers', 'billing', 'parts_inventory', 'balance_sheet', 'reports',
            ...$extra,
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
