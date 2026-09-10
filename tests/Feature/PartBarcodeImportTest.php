<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Tests\TestCase;

class PartBarcodeImportTest extends TestCase
{
    public function test_creating_a_part_without_barcode_assigns_one(): void
    {
        $user = $this->owner();
        Sanctum::actingAs($user);

        $part = $this->postJson('/api/parts', [
            'name' => 'USB Cable',
            'brand' => 'Baseus',
            'type' => 'cable',
            'price' => 1200,
            'cost_price' => 700,
            'stock_qty' => 5,
        ])->assertCreated()->json();

        $this->assertNotEmpty($part['barcode']);
        $this->assertStringStartsWith('POS', $part['barcode']);
    }

    public function test_ensure_barcode_fills_missing_code(): void
    {
        $user = $this->owner();
        Sanctum::actingAs($user);

        $part = Part::create([
            'name' => 'Legacy Item',
            'brand' => 'OEM',
            'type' => 'misc',
            'price' => 500,
            'cost_price' => 200,
            'stock_qty' => 1,
            'barcode' => 'TEMP-CLEAR',
        ]);
        // Simulate an older row with no barcode without re-triggering creating hook.
        Part::query()->whereKey($part->id)->update(['barcode' => null]);
        $part->refresh();
        $this->assertNull($part->barcode);

        $payload = $this->postJson("/api/parts/{$part->id}/ensure-barcode")->assertOk()->json();
        $this->assertNotEmpty($payload['barcode']);
        $this->assertStringStartsWith('POS', $payload['barcode']);
        $this->assertSame($payload['barcode'], $part->fresh()->barcode);
    }

    public function test_import_same_barcode_different_names_creates_two_parts(): void
    {
        $user = $this->owner();
        Sanctum::actingAs($user);

        $csv = $this->csvFile([
            ['name', 'sku', 'barcode', 'brand', 'type', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'description', 'payment_status', 'due_date'],
            ['Charger Doc Lightning', 'DOC', '8901111222333', 'Baseus', 'charger', '', '', '2500', '1500', '3', '', 'paid', ''],
            ['Charger Doc USB-C', 'DOC', '8901111222333', 'Baseus', 'charger', '', '', '2500', '1500', '4', '', 'paid', ''],
        ]);

        $result = $this->post('/api/parts/import', [
            'file' => $csv,
            'payment_status' => 'paid',
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);

        $parts = Part::query()->orderBy('id')->get();
        $this->assertCount(2, $parts);
        $this->assertSame('Charger Doc Lightning', $parts[0]->name);
        $this->assertSame('8901111222333', $parts[0]->barcode);
        $this->assertSame('DOC', $parts[0]->sku);

        $this->assertSame('Charger Doc USB-C', $parts[1]->name);
        $this->assertNotSame('8901111222333', $parts[1]->barcode);
        $this->assertStringStartsWith('POS', $parts[1]->barcode);
        $this->assertNull($parts[1]->sku);
        $this->assertStringContainsString('Supplier barcode: 8901111222333', (string) $parts[1]->description);
    }

    public function test_import_same_barcode_same_name_merges_stock(): void
    {
        $user = $this->owner();
        Sanctum::actingAs($user);

        Part::create([
            'name' => 'Oil Filter',
            'sku' => 'OF-1',
            'barcode' => '8909999888777',
            'brand' => 'Bosch',
            'type' => 'filter',
            'price' => 1500,
            'cost_price' => 900,
            'stock_qty' => 2,
        ]);

        $csv = $this->csvFile([
            ['name', 'sku', 'barcode', 'brand', 'type', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'description', 'payment_status', 'due_date'],
            ['Oil Filter', 'OF-1', '8909999888777', 'Bosch', 'filter', '', '', '1600', '950', '5', '', 'paid', ''],
        ]);

        $result = $this->post('/api/parts/import', [
            'file' => $csv,
            'payment_status' => 'paid',
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, Part::query()->count());
        $this->assertSame(7, Part::query()->first()->stock_qty);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csvFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'parts').'.csv';
        (new Csv($spreadsheet))->save($path);

        return new UploadedFile($path, 'parts.csv', 'text/csv', null, true);
    }

    private function owner(): User
    {
        $features = collect(['admit_vehicle', 'customers', 'billing', 'parts_inventory', 'balance_sheet', 'reports'])
            ->map(fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0]));
        $tenant = Tenant::create([
            'business_name' => fake()->company(),
            'business_type' => 'store',
            'owner_name' => fake()->name(),
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);
    }
}
