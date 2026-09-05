<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Part;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Services\BranchInventory;
use App\Support\InventoryCosting;
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

        $parts->getCollection()->transform(fn (Part $part) => BranchInventory::overlayPart($part));

        return $this->moneyJson($parts);
    }

    public function importTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Parts');

        $headers = $this->importHeaders();
        $sheet->fromArray($headers, null, 'A1');
        // Two sample rows: paid (no due date) and credit (due_date required).
        $creditDueDate = now()->addDays(30)->toDateString();
        $sheet->fromArray([
            [
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
                'SAMPLE paid — money already paid; leave due_date blank',
                'paid',
                '',
            ],
            [
                'Brake Pads',
                'BP-002',
                '8901234567891',
                'Nissin',
                'Braking',
                'Vezel',
                '2019',
                '7800.00',
                '4500.00',
                '5',
                'SAMPLE credit — supplier owe; due_date required (YYYY-MM-DD)',
                'credit',
                $creditDueDate,
            ],
        ], null, 'A2');

        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->getStyle('A1:M1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('167C73');
        $sheet->getStyle('A1:M1')->getFont()->getColor()->setRGB('FFFFFF');
        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['Use the Parts sheet. Keep these required headers: name, brand, type, price, cost_price, stock_qty.'],
            ['Optional columns: sku, barcode, model, year, description, payment_status, due_date.'],
            ['payment_status: paid or debit = money already paid (hits Finance now). credit = supplier owe / inventory on credit.'],
            ['Sample row A2 (Oil Filter): payment_status=paid, due_date blank — paid rows do not need a due date.'],
            ['Sample row A3 (Brake Pads): payment_status=credit, due_date filled (YYYY-MM-DD) — credit rows require a due date.'],
            ['due_date is required on a credit row (YYYY-MM-DD). You can also set a default paid/credit when uploading.'],
            ['Expense amount for each row = cost_price × stock_qty (created when stock_qty > 0 and cost_price > 0).'],
            ['If barcode or sku already exists, stock_qty is added and cost_price becomes a weighted average of old stock + this row’s cost_price × qty.'],
            ['Expense for each row still uses this row’s cost_price × stock_qty (the actual purchase), not the blended catalogue cost.'],
            ['Older templates without payment_status still work — those rows follow the upload default (paid unless you choose credit).'],
            ['Delete both SAMPLE rows before importing your real data. Save as .xlsx.'],
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
            'payment_status' => ['nullable', Rule::in(['paid', 'credit', 'debit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
        ]);
        $defaultPayment = $this->normalizePaymentStatus($request->input('payment_status', 'paid'));
        $defaultDueDate = $request->input('due_date');

        $path = $request->file('file')->getRealPath();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);

        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => ['The spreadsheet has no data rows. Download the template and fill it in.']]);
        }

        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($rows));
        $headerIndex = [];
        foreach ($headers as $columnIndex => $key) {
            if ($key !== '') {
                $headerIndex[$key] = $columnIndex;
            }
        }
        $missing = array_values(array_filter(
            $this->requiredImportHeaders(),
            fn (string $key) => ! array_key_exists($key, $headerIndex)
        ));
        if ($missing) {
            throw ValidationException::withMessages([
                'file' => ['Missing required columns: '.implode(', ', $missing).'. Download a fresh template.'],
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
            foreach (array_unique([...$this->importHeaders(), 'payment_status', 'due_date']) as $key) {
                $columnIndex = $headerIndex[$key] ?? null;
                $values[$key] = $columnIndex !== null && isset($row[$columnIndex]) ? trim((string) $row[$columnIndex]) : '';
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

            $rowPayment = $values['payment_status'] !== ''
                ? $this->normalizePaymentStatus($values['payment_status'])
                : $defaultPayment;
            if ($values['payment_status'] !== '' && ! in_array(strtolower($values['payment_status']), ['paid', 'debit', 'credit', 'cash'], true)) {
                $rowErrors[] = 'payment_status must be paid, debit, or credit';
            }
            $rowDue = $values['due_date'] !== '' ? $values['due_date'] : $defaultDueDate;
            if ($rowPayment === 'credit' && ! $rowDue) {
                $rowErrors[] = 'due_date is required for credit / supplier-owe rows';
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
                'payment_status' => $rowPayment,
                'due_date' => $rowPayment === 'credit' ? $rowDue : null,
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
                    $blendedCost = InventoryCosting::weightedAverageCost(
                        (int) $existing->stock_qty,
                        $existing->cost_price,
                        $qty,
                        $unitCost,
                    );
                    $existing->update([
                        'name' => $row['name'],
                        'sku' => $row['sku'] ?? $existing->sku,
                        'barcode' => $row['barcode'] ?? $existing->barcode,
                        'brand' => $row['brand'],
                        'type' => $row['type'],
                        'model' => $row['model'],
                        'year' => $row['year'],
                        'price' => $row['price'],
                        'cost_price' => $blendedCost,
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

                $expense = $this->recordPurchaseExpense(
                    $request,
                    $part,
                    $qty,
                    $unitCost,
                    null,
                    $row['payment_status'] ?? 'paid',
                    $row['due_date'] ?? null,
                );
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
                null,
                $request->input('payment_status', 'paid'),
                $request->input('due_date'),
            );

            return $part->refresh();
        });

        return response()->json($part, 201);
    }

    public function show(Part $part): JsonResponse
    {
        return $this->moneyJson(BranchInventory::overlayPart($part));
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
        if ($request->input('due_date') === '') {
            $request->merge(['due_date' => null]);
        }
        if ($request->input('supplier_id') === '' || $request->input('supplier_id') === '0') {
            $request->merge(['supplier_id' => null]);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expense_date' => ['nullable', 'date'],
            'payment_status' => ['nullable', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        [$part, $expense] = DB::transaction(function () use ($data, $part, $request) {
            $qty = (int) $data['quantity'];
            $unitCost = (float) ($data['unit_cost'] ?? $part->cost_price ?? 0);
            $shopQty = BranchInventory::partQty($part->id);
            $blendedCost = InventoryCosting::weightedAverageCost(
                $shopQty,
                $part->cost_price,
                $qty,
                $unitCost,
            );
            BranchInventory::addPart($part, $qty);
            $part->refresh();
            $part->update(['cost_price' => $blendedCost]);
            $expense = $this->recordPurchaseExpense(
                $request,
                $part->refresh(),
                $qty,
                $unitCost,
                $data['expense_date'] ?? null,
                $data['payment_status'] ?? 'paid',
                $data['due_date'] ?? null,
                $data['supplier_id'] ?? null,
            );

            return [$part->refresh(), $expense];
        });

        return response()->json(['part' => BranchInventory::overlayPart($part), 'expense' => $expense]);
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
            'payment_status' => ['nullable', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
        ]);

        unset($data['images'], $data['payment_status'], $data['due_date']);

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

    private function recordPurchaseExpense(
        Request $request,
        Part $part,
        int $quantity,
        float $unitCost,
        ?string $expenseDate = null,
        string $paymentStatus = 'paid',
        ?string $dueDate = null,
        ?int $supplierId = null,
    ): ?Expense {
        if ($quantity <= 0 || $unitCost <= 0) {
            return null;
        }

        $paid = $paymentStatus !== Expense::STATUS_CREDIT;
        $date = $expenseDate ?? now()->toDateString();

        $expense = Expense::create([
            'category' => 'inventory',
            'description' => $paid
                ? "Stock purchase: {$part->name} × {$quantity}"
                : "Stock purchase on credit: {$part->name} × {$quantity}",
            'amount' => round($unitCost * $quantity, 2),
            'expense_date' => $date,
            'payment_status' => $paid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
            'due_date' => $paid ? null : $dueDate,
            'settled_at' => $paid ? $date : null,
            'created_by' => $request->user()->id,
            'supplier_id' => $supplierId,
        ]);

        if ($supplierId) {
            $count = StockReceipt::query()->count() + 1;
            $receipt = StockReceipt::create([
                'supplier_id' => $supplierId,
                'expense_id' => $expense->id,
                'receipt_number' => 'GRN-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT),
                'received_at' => $date,
                'payment_status' => $paid ? 'paid' : 'credit',
                'due_date' => $paid ? null : $dueDate,
            ]);
            StockReceiptItem::create([
                'stock_receipt_id' => $receipt->id,
                'item_type' => 'part',
                'part_id' => $part->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]);
            $expense->update(['stock_receipt_id' => $receipt->id]);
        }

        return $expense;
    }

    /**
     * @return list<string>
     */
    private function importHeaders(): array
    {
        return ['name', 'sku', 'barcode', 'brand', 'type', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'description', 'payment_status', 'due_date'];
    }

    /**
     * @return list<string>
     */
    private function requiredImportHeaders(): array
    {
        return ['name', 'brand', 'type', 'price', 'cost_price', 'stock_qty'];
    }

    private function normalizePaymentStatus(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['credit'], true) ? 'credit' : 'paid';
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
