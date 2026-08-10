<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allowedSorts = ['name', 'brand', 'type', 'model', 'year', 'price', 'stock_qty', 'created_at'];
        $sort = in_array($request->string('sort')->toString(), $allowedSorts, true) ? $request->string('sort') : 'name';
        $direction = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        $parts = Part::query()
            ->when($request->filled('barcode'), fn ($query) => $query->where('barcode', $request->string('barcode')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('sku', 'like', '%'.$request->string('search').'%')
                ->orWhere('barcode', 'like', '%'.$request->string('search').'%')
                ->orWhere('brand', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('brand'), fn ($query) => $query->where('brand', $request->string('brand')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('model'), fn ($query) => $query->where('model', 'like', '%'.$request->string('model').'%'))
            ->when($request->filled('year'), fn ($query) => $query->where('year', $request->integer('year')))
            ->orderBy($sort, $direction)
            ->paginate($request->integer('per_page', 20));

        return $this->moneyJson($parts);
    }

    public function importTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Parts');

        $headers = $this->importHeaders();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            'Oil Filter',
            'OF-001',
            '8901234567890',
            'Bosch',
            'Filters',
            'Axio',
            '2018',
            '1500.00',
            '900.00',
            '10',
            'Replace this sample row with your parts',
        ], null, 'A2');

        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('167C73');
        $sheet->getStyle('A1:K1')->getFont()->getColor()->setRGB('FFFFFF');
        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['Use the Parts sheet only. Keep the header row exactly as provided.'],
            ['Required columns: name, brand, type, price, cost_price, stock_qty'],
            ['Optional columns: sku, barcode, model, year, description'],
            ['Expense amount for each row = cost_price × stock_qty (created one expense per imported row when stock_qty > 0 and cost_price > 0).'],
            ['If barcode or sku already exists, stock_qty is added to that part (restock) and one expense is created for the added quantity.'],
            ['Delete the sample row before importing your data.'],
            ['Save the file as .xlsx before uploading.'],
        ], null, 'A1');
        $instructions->getColumnDimension('A')->setWidth(110);

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'parts-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $path = $request->file('file')->getRealPath();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);

        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => ['The spreadsheet has no data rows. Download the template and fill it in.']]);
        }

        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($rows));
        $expected = $this->importHeaders();
        if ($headers !== $expected) {
            throw ValidationException::withMessages([
                'file' => ['Column headers must match the template exactly: '.implode(', ', $expected)],
            ]);
        }

        $parsed = [];
        $errors = [];
        $seenSku = [];
        $seenBarcode = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $values = [];
            foreach ($expected as $columnIndex => $key) {
                $values[$key] = isset($row[$columnIndex]) ? trim((string) $row[$columnIndex]) : '';
            }

            $rowErrors = [];
            foreach (['name', 'brand', 'type', 'price', 'cost_price', 'stock_qty'] as $required) {
                if ($values[$required] === '') {
                    $rowErrors[] = "{$required} is required";
                }
            }
            if ($values['price'] !== '' && ! is_numeric($values['price'])) {
                $rowErrors[] = 'price must be a number';
            }
            if ($values['cost_price'] !== '' && ! is_numeric($values['cost_price'])) {
                $rowErrors[] = 'cost_price must be a number';
            }
            if ($values['stock_qty'] !== '' && (! is_numeric($values['stock_qty']) || (float) $values['stock_qty'] < 0 || (int) $values['stock_qty'] != (float) $values['stock_qty'])) {
                $rowErrors[] = 'stock_qty must be a whole number';
            }
            if ($values['year'] !== '' && (! ctype_digit($values['year']) || (int) $values['year'] < 1900 || (int) $values['year'] > now()->year + 1)) {
                $rowErrors[] = 'year is invalid';
            }
            if ($values['sku'] !== '') {
                $skuKey = strtolower($values['sku']);
                if (isset($seenSku[$skuKey])) {
                    $rowErrors[] = "sku duplicated with row {$seenSku[$skuKey]}";
                } else {
                    $seenSku[$skuKey] = $line;
                }
            }
            if ($values['barcode'] !== '') {
                $barcodeKey = strtolower($values['barcode']);
                if (isset($seenBarcode[$barcodeKey])) {
                    $rowErrors[] = "barcode duplicated with row {$seenBarcode[$barcodeKey]}";
                } else {
                    $seenBarcode[$barcodeKey] = $line;
                }
            }

            $match = null;
            if ($values['barcode'] !== '') {
                $match = Part::query()->where('barcode', $values['barcode'])->first();
            }
            if (! $match && $values['sku'] !== '') {
                $match = Part::query()->where('sku', $values['sku'])->first();
            }
            if ($values['sku'] !== '') {
                $skuOwner = Part::query()->where('sku', $values['sku'])->first();
                if ($skuOwner && (! $match || $skuOwner->id !== $match->id)) {
                    $rowErrors[] = 'sku already belongs to another part';
                }
            }
            if ($values['barcode'] !== '') {
                $barcodeOwner = Part::query()->where('barcode', $values['barcode'])->first();
                if ($barcodeOwner && (! $match || $barcodeOwner->id !== $match->id)) {
                    $rowErrors[] = 'barcode already belongs to another part';
                }
            }

            if ($rowErrors) {
                $errors[] = "Row {$line}: ".implode('; ', $rowErrors);

                continue;
            }

            $parsed[] = [
                'line' => $line,
                'name' => $values['name'],
                'sku' => $values['sku'] !== '' ? $values['sku'] : null,
                'barcode' => $values['barcode'] !== '' ? $values['barcode'] : null,
                'brand' => $values['brand'],
                'type' => $values['type'],
                'model' => $values['model'] !== '' ? $values['model'] : null,
                'year' => $values['year'] !== '' ? (int) $values['year'] : null,
                'price' => round((float) $values['price'], 2),
                'cost_price' => round((float) $values['cost_price'], 2),
                'stock_qty' => (int) $values['stock_qty'],
                'description' => $values['description'] !== '' ? $values['description'] : null,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        if ($parsed === []) {
            throw ValidationException::withMessages(['file' => ['No part rows found. Keep the header row and add at least one data row.']]);
        }

        $summary = DB::transaction(function () use ($parsed, $request) {
            $created = 0;
            $updated = 0;
            $expenses = 0;
            $expenseTotal = 0.0;

            foreach ($parsed as $row) {
                $existing = null;
                if ($row['barcode']) {
                    $existing = Part::query()->where('barcode', $row['barcode'])->first();
                }
                if (! $existing && $row['sku']) {
                    $existing = Part::query()->where('sku', $row['sku'])->first();
                }

                $qty = $row['stock_qty'];
                $unitCost = $row['cost_price'];

                if ($existing) {
                    $existing->update([
                        'name' => $row['name'],
                        'sku' => $row['sku'] ?? $existing->sku,
                        'barcode' => $row['barcode'] ?? $existing->barcode,
                        'brand' => $row['brand'],
                        'type' => $row['type'],
                        'model' => $row['model'],
                        'year' => $row['year'],
                        'price' => $row['price'],
                        'cost_price' => $unitCost,
                        'description' => $row['description'],
                        'stock_qty' => $existing->stock_qty + $qty,
                    ]);
                    $part = $existing->refresh();
                    $updated++;
                } else {
                    $part = Part::create([
                        'name' => $row['name'],
                        'sku' => $row['sku'],
                        'barcode' => $row['barcode'],
                        'brand' => $row['brand'],
                        'type' => $row['type'],
                        'model' => $row['model'],
                        'year' => $row['year'],
                        'price' => $row['price'],
                        'cost_price' => $unitCost,
                        'stock_qty' => $qty,
                        'description' => $row['description'],
                    ]);
                    $created++;
                }

                $expense = $this->recordPurchaseExpense($request, $part, $qty, $unitCost);
                if ($expense) {
                    $expenses++;
                    $expenseTotal += (float) $expense->amount;
                }
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'expenses_created' => $expenses,
                'expense_total' => round($expenseTotal, 2),
                'rows' => count($parsed),
            ];
        });

        return response()->json([
            'message' => 'Parts imported successfully.',
            ...$summary,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $part = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $part = Part::create($data);
            $this->storeImages($request, $part);
            $this->recordPurchaseExpense(
                $request,
                $part,
                (int) ($data['stock_qty'] ?? 0),
                (float) ($data['cost_price'] ?? 0),
            );

            return $part->refresh();
        });

        return response()->json($part, 201);
    }

    public function show(Part $part): JsonResponse
    {
        return $this->moneyJson($part);
    }

    public function update(Request $request, Part $part): JsonResponse
    {
        $part->update($this->validated($request, $part));
        $this->storeImages($request, $part);

        return response()->json($part->refresh());
    }

    public function destroy(Part $part): JsonResponse
    {
        foreach ($part->images ?? [] as $image) {
            Storage::disk('public')->delete($image);
        }
        $part->delete();

        return response()->json(null, 204);
    }

    public function image(Request $request, Part $part): JsonResponse
    {
        $this->normalizeImageUploads($request);
        $request->validate(['images' => ['required', 'array', 'max:5'], 'images.*' => ['image', 'max:5120']]);
        $this->storeImages($request, $part);

        return response()->json($part->refresh());
    }

    public function restock(Request $request, Part $part): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expense_date' => ['nullable', 'date'],
        ]);

        [$part, $expense] = DB::transaction(function () use ($data, $part, $request) {
            $unitCost = (float) ($data['unit_cost'] ?? $part->cost_price ?? 0);
            $part->increment('stock_qty', $data['quantity']);
            if ($data['unit_cost'] !== null) {
                $part->update(['cost_price' => $unitCost]);
            }
            $expense = $this->recordPurchaseExpense(
                $request,
                $part->refresh(),
                (int) $data['quantity'],
                $unitCost,
                $data['expense_date'] ?? null,
            );

            return [$part->refresh(), $expense];
        });

        return response()->json(['part' => $part, 'expense' => $expense]);
    }

    private function validated(Request $request, ?Part $part = null): array
    {
        $this->normalizeImageUploads($request);

        $data = $request->validate([
            'name' => [$part ? 'sometimes' : 'required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('parts')->where('tenant_id', $request->user()->tenant_id)->ignore($part)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('parts')->where('tenant_id', $request->user()->tenant_id)->ignore($part)],
            'brand' => [$part ? 'sometimes' : 'required', 'string', 'max:100'],
            'type' => [$part ? 'sometimes' : 'required', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'price' => [$part ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => [$part ? 'sometimes' : 'required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['image', 'max:5120'],
        ]);

        unset($data['images']);

        foreach (['sku', 'barcode'] as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === null || trim((string) $data[$field]) === '')) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function storeImages(Request $request, Part $part): void
    {
        $this->normalizeImageUploads($request);

        if (! $request->hasFile('images')) {
            return;
        }

        $files = $request->file('images');
        $files = is_array($files) ? $files : [$files];

        $images = $part->images ?? [];
        foreach ($files as $image) {
            if (! $image) {
                continue;
            }
            $images[] = $image->store('parts', 'public');
        }
        $part->update(['images' => array_slice($images, 0, 5)]);
    }

    private function normalizeImageUploads(Request $request): void
    {
        // HTML forms may send images[] which Laravel maps to images.
        if ($request->hasFile('images') === false && $request->files->has('images')) {
            $request->files->remove('images');
        }

        if (! $request->hasFile('images')) {
            $request->request->remove('images');
            $request->files->remove('images');

            return;
        }

        $files = $request->file('images');
        if ($files && ! is_array($files)) {
            $request->files->set('images', [$files]);
        }
    }

    private function recordPurchaseExpense(Request $request, Part $part, int $quantity, float $unitCost, ?string $expenseDate = null): ?Expense
    {
        if ($quantity <= 0 || $unitCost <= 0) {
            return null;
        }

        return Expense::create([
            'category' => 'inventory',
            'description' => "Stock purchase: {$part->name} × {$quantity}",
            'amount' => round($unitCost * $quantity, 2),
            'expense_date' => $expenseDate ?? now()->toDateString(),
            'created_by' => $request->user()->id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function importHeaders(): array
    {
        return ['name', 'sku', 'barcode', 'brand', 'type', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'description'];
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $value)));
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
