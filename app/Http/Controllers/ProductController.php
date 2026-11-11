<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\PartCategory;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Services\BranchInventory;
use App\Support\BusinessTypes;
use App\Support\ClothingSizes;
use App\Support\InventoryCosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search').'%';
                $q->where(fn ($n) => $n->where('name', 'like', $search)
                    ->orWhere('sku', 'like', $search)
                    ->orWhere('barcode', 'like', $search)
                    ->orWhere('category', 'like', $search)
                    ->orWhere('subcategory', 'like', $search)
                    ->orWhere('color', 'like', $search)
                    ->orWhere('size', 'like', $search));
            })
            ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        BranchInventory::overlayProducts($products->getCollection());

        return $this->moneyJson($products);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->input('barcode') === '') {
            $request->merge(['barcode' => null]);
        }
        $expanded = $this->expandedSizes($request);
        if ($expanded !== null) {
            $base = $this->validated($request);
            $sku = $base['sku'] ?? null;
            $barcode = $base['barcode'] ?? null;
            unset($base['size'], $base['stock_qty'], $base['sku'], $base['barcode']);
            if ($barcode) {
                foreach ($expanded as $row) {
                    $this->assertBarcode($barcode, $row['size'], (string) $base['name'], $base['color'] ?? null, $base['category'] ?? null);
                }
            }
            $created = [];
            foreach ($expanded as $row) {
                $product = Product::create([
                    ...$base,
                    'size' => $row['size'],
                    'stock_qty' => $row['stock_qty'],
                    'sku' => $sku ? $sku.'-'.str_replace(' ', '', $row['size']) : null,
                    'barcode' => $barcode,
                ]);
                $this->storeImages($request, $product);
                $created[] = $product->refresh();
            }

            return response()->json(['id' => $created[0]->id, 'products' => $created], 201);
        }

        $data = $this->validated($request);
        if (! empty($data['barcode'])) {
            $this->assertBarcode($data['barcode'], $data['size'] ?? null, (string) $data['name'], $data['color'] ?? null, $data['category'] ?? null);
        }
        $product = Product::create($data);
        $this->storeImages($request, $product);

        return response()->json($product->refresh(), 201);
    }

    public function sizeStock(Request $request): JsonResponse
    {
        $products = Product::query()->where('active', true)->get(['id', 'name', 'category', 'subcategory', 'size', 'stock_qty']);
        BranchInventory::overlayProducts($products);

        $boards = [
            'apparel' => array_fill_keys(ClothingSizes::APPAREL, 0),
            'jeans' => array_fill_keys(ClothingSizes::JEANS, 0),
            'footwear' => array_fill_keys(ClothingSizes::FOOTWEAR, 0),
        ];
        $other = [];
        foreach ($products as $product) {
            $size = trim((string) $product->size);
            if ($size === '') {
                continue;
            }
            $qty = (int) $product->stock_qty;
            $scale = ClothingSizes::scaleOf($size);
            if (isset($boards[$scale]) && array_key_exists($size, $boards[$scale])) {
                $boards[$scale][$size] += $qty;
                continue;
            }
            if ($scale === 'jeans' || $scale === 'apparel' || $scale === 'footwear') {
                $boards[$scale][$size] = ($boards[$scale][$size] ?? 0) + $qty;
                continue;
            }
            $other[$size] = ($other[$size] ?? 0) + $qty;
        }

        return response()->json([
            'apparel' => $this->sizeRows($boards['apparel']),
            'jeans' => $this->sizeRows($boards['jeans']),
            'footwear' => $this->sizeRows($boards['footwear']),
            'other' => $this->sizeRows($other),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        return $this->moneyJson(BranchInventory::overlayProduct($product));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        if ($request->input('barcode') === '') {
            $request->merge(['barcode' => null]);
        }
        $data = $this->validated($request, $product);
        $before = [
            'name' => $product->name,
            'color' => $product->color,
            'category' => $product->category,
        ];
        $name = array_key_exists('name', $data) ? $data['name'] : $product->name;
        $color = array_key_exists('color', $data) ? $data['color'] : $product->color;
        $category = array_key_exists('category', $data) ? $data['category'] : $product->category;
        $size = array_key_exists('size', $data) ? $data['size'] : $product->size;
        $barcode = array_key_exists('barcode', $data) ? $data['barcode'] : $product->barcode;
        if ($barcode) {
            $this->assertBarcode(
                (string) $barcode,
                $size,
                (string) $name,
                $color,
                $category,
                $this->styleIds($before['name'], $before['color'], $before['category']),
            );
        }
        $product->update($data);
        $this->storeImages($request, $product);
        $product->refresh();
        if ($this->clothingTenant($request)) {
            $this->syncStyle($product, $before);
        }

        return response()->json($product->refresh());
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    public function restock(Request $request, Product $product): JsonResponse
    {
        if ($request->input('due_date') === '') {
            $request->merge(['due_date' => null]);
        }
        if ($request->input('supplier_id') === '' || $request->input('supplier_id') === '0') {
            $request->merge(['supplier_id' => null]);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expense_date' => ['nullable', 'date'],
            'payment_status' => ['nullable', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        [$product, $expense] = DB::transaction(function () use ($data, $product, $request) {
            $qty = (int) $data['quantity'];
            $unitCostProvided = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null;
            $unitCost = $unitCostProvided
                ? (float) $data['unit_cost']
                : (float) ($product->cost_price ?? 0);
            $shopQty = BranchInventory::productQty($product->id);
            $blendedCost = InventoryCosting::catalogueCost(
                $request->user()?->tenant?->inventory_cost_method,
                $shopQty,
                $product->cost_price,
                $qty,
                $unitCost,
            );
            BranchInventory::returnProduct($product, $qty);
            $product->refresh();
            $product->update([
                'cost_price' => $blendedCost,
            ]);

            $expense = null;
            // Only book an expense when a purchase unit cost was entered (same rule as before).
            if ($unitCostProvided && $unitCost > 0) {
                $paid = ($data['payment_status'] ?? 'paid') !== Expense::STATUS_CREDIT;
                $date = $data['expense_date'] ?? now()->toDateString();
                $expense = Expense::create([
                    'category' => 'inventory',
                    'description' => $paid
                        ? "Stock purchase: {$product->name} × {$qty}"
                        : "Stock purchase on credit: {$product->name} × {$qty}",
                    'amount' => round($unitCost * $qty, 2),
                    'expense_date' => $date,
                    'payment_status' => $paid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
                    'due_date' => $paid ? null : ($data['due_date'] ?? null),
                    'settled_at' => $paid ? $date : null,
                    'created_by' => $request->user()->id,
                    'supplier_id' => $data['supplier_id'] ?? null,
                ]);

                if (! empty($data['supplier_id'])) {
                    $count = StockReceipt::query()->count() + 1;
                    $receipt = StockReceipt::create([
                        'supplier_id' => $data['supplier_id'],
                        'expense_id' => $expense->id,
                        'receipt_number' => 'GRN-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT),
                        'received_at' => $date,
                        'payment_status' => $paid ? 'paid' : 'credit',
                        'due_date' => $paid ? null : ($data['due_date'] ?? null),
                    ]);
                    StockReceiptItem::create([
                        'stock_receipt_id' => $receipt->id,
                        'item_type' => 'product',
                        'product_id' => $product->id,
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                    ]);
                    $expense->update(['stock_receipt_id' => $receipt->id]);
                }
            }

            return [$product->refresh(), $expense];
        });

        return response()->json($product);
    }

    public function image(Request $request, Product $product): JsonResponse
    {
        $this->normalizeImageUploads($request);
        $request->validate(['images' => ['required', 'array', 'max:5'], 'images.*' => ['image', 'max:5120']]);
        $this->storeImages($request, $product);

        return response()->json($product->refresh());
    }

    public function restockBulk(Request $request): JsonResponse
    {
        foreach (['due_date', 'invoice_number', 'expense_date'] as $field) {
            if (trim((string) $request->input($field, '')) === '') {
                $request->merge([$field => null]);
            }
        }
        if ($request->input('supplier_id') === '' || $request->input('supplier_id') === '0') {
            $request->merge(['supplier_id' => null]);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['nullable', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'expense_date' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
        ]);

        $clothing = $request->user()?->tenant?->business_type === BusinessTypes::CLOTHING;
        $result = DB::transaction(function () use ($data, $request, $clothing) {
            $lines = [];
            foreach ($data['items'] as $row) {
                $product = Product::query()->whereKey($row['product_id'])->lockForUpdate()->firstOrFail();
                $qty = (int) $row['quantity'];
                $unitCostProvided = array_key_exists('unit_cost', $row) && $row['unit_cost'] !== null && $row['unit_cost'] !== '';
                $unitCost = $unitCostProvided
                    ? (float) $row['unit_cost']
                    : (float) ($product->cost_price ?? 0);
                $shopQty = BranchInventory::productQty($product->id);
                $blendedCost = InventoryCosting::catalogueCost(
                    $request->user()?->tenant?->inventory_cost_method,
                    $shopQty,
                    $product->cost_price,
                    $qty,
                    $unitCost,
                );
                BranchInventory::returnProduct($product, $qty);
                $product->refresh();
                $product->update(['cost_price' => $blendedCost]);
                if (array_key_exists('price', $row) && $row['price'] !== null && $row['price'] !== '') {
                    $this->applySalePrice($product, round((float) $row['price'], 2), $clothing);
                }
                $lines[] = [
                    'product' => $product->refresh(),
                    'quantity' => $qty,
                    'unit_cost' => $unitCostProvided ? $unitCost : 0.0,
                    'billable' => $unitCostProvided && $unitCost > 0,
                ];
            }

            $payable = round(array_sum(array_map(
                fn (array $line) => $line['billable'] ? $line['quantity'] * $line['unit_cost'] : 0,
                $lines,
            )), 2);
            $paid = ($data['payment_status'] ?? 'paid') !== Expense::STATUS_CREDIT;
            $date = $data['expense_date'] ?? now()->toDateString();
            $invoice = isset($data['invoice_number']) ? trim((string) $data['invoice_number']) : '';
            $names = collect($lines)->map(fn (array $line) => $line['product']->name)->unique()->values();
            $label = $invoice !== ''
                ? "invoice {$invoice}"
                : ($names->count() === 1 ? (string) $names->first() : $names->take(2)->implode(', '));

            $expense = null;
            if ($payable > 0) {
                $expense = Expense::create([
                    'category' => 'inventory',
                    'description' => ($paid ? 'Stock purchase: ' : 'Stock purchase on credit: ').$label,
                    'amount' => $payable,
                    'expense_date' => $date,
                    'payment_status' => $paid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
                    'due_date' => $paid ? null : ($data['due_date'] ?? null),
                    'settled_at' => $paid ? $date : null,
                    'created_by' => $request->user()->id,
                    'supplier_id' => $data['supplier_id'] ?? null,
                ]);
            }

            $count = StockReceipt::query()->count() + 1;
            $receipt = StockReceipt::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'expense_id' => $expense?->id,
                'receipt_number' => 'GRN-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT),
                'invoice_number' => $invoice !== '' ? $invoice : null,
                'received_at' => $date,
                'payment_status' => $paid ? 'paid' : 'credit',
                'due_date' => $paid ? null : ($data['due_date'] ?? null),
            ]);
            foreach ($lines as $line) {
                StockReceiptItem::create([
                    'stock_receipt_id' => $receipt->id,
                    'item_type' => 'product',
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ]);
            }
            $expense?->update(['stock_receipt_id' => $receipt->id]);

            return ['updated' => count($lines), 'receipt' => $receipt->load('items')];
        });

        return response()->json($result);
    }

    private function applySalePrice(Product $product, float $price, bool $clothing): void
    {
        $product->update(['price' => $price]);
        if (! $clothing) {
            return;
        }

        $this->styleQuery($product->name, $product->color, $product->category)
            ->whereKeyNot($product->id)
            ->update(['price' => $price]);
    }

    public function importTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');
        $headers = ['name', 'sku', 'barcode', 'category', 'subcategory', 'size', 'color', 'price', 'cost_price', 'stock_qty', 'description', 'payment_status', 'due_date'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            ['Oxford Shirt', 'OX-001', '8901000000001', 'Men', 'Shirts', 'M', 'White', '4500.00', '2200.00', '8', 'SAMPLE — delete this row', 'paid', ''],
        ], null, 'A2');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('167C73');
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->getColor()->setRGB('FFFFFF');
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['Required column: name.'],
            ['Optional: sku, barcode, category, subcategory, size, color, price, cost_price, stock_qty, description, payment_status, due_date.'],
            ['payment_status paid books the purchase now. credit needs a due_date (YYYY-MM-DD) and the supplier chosen in the import dialog.'],
            ['A matching barcode and size adds the stock quantity to that size. The same barcode with a new size adds that size to the garment.'],
            ['Delete the SAMPLE row before importing. Save as .xlsx.'],
        ], null, 'A1');
        $instructions->getColumnDimension('A')->setWidth(120);
        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'catalog-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'payment_status' => ['nullable', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
            'supplier_id' => ['nullable', 'required_if:payment_status,credit', Rule::exists('suppliers', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);
        $defaultPayment = $request->input('payment_status', 'paid') === 'credit' ? 'credit' : 'paid';
        $defaultDue = $request->input('due_date');
        $supplierId = $request->input('supplier_id');
        $rows = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), array_shift($rows) ?: []);
        if (! in_array('name', $header, true)) {
            abort(422, 'The file needs a name column.');
        }

        $created = 0;
        $restocked = 0;
        DB::transaction(function () use ($rows, $header, $defaultPayment, $defaultDue, $supplierId, $request, &$created, &$restocked) {
            foreach ($rows as $row) {
                $values = [];
                foreach ($header as $index => $key) {
                    if ($key !== '') {
                        $values[$key] = trim((string) ($row[$index] ?? ''));
                    }
                }
                $name = $values['name'] ?? '';
                if ($name === '' || str_contains(strtoupper($values['description'] ?? ''), 'SAMPLE')) {
                    continue;
                }
                $barcode = ($values['barcode'] ?? '') !== '' ? $values['barcode'] : null;
                $size = ($values['size'] ?? '') !== '' ? $values['size'] : null;
                $color = ($values['color'] ?? '') !== '' ? $values['color'] : null;
                $category = ($values['category'] ?? '') !== '' ? $values['category'] : null;
                $existing = null;
                if ($barcode) {
                    $existing = Product::query()->where('barcode', $barcode)->where('size', $size)->first();
                    if (! $existing) {
                        $this->assertBarcode($barcode, $size, $name, $color, $category);
                    }
                }
                $qty = (int) ($values['stock_qty'] ?? 0);
                $cost = ($values['cost_price'] ?? '') !== '' ? (float) $values['cost_price'] : 0;
                $payment = ($values['payment_status'] ?? '') !== '' ? $values['payment_status'] : $defaultPayment;
                $payment = $payment === 'credit' ? 'credit' : 'paid';
                $due = ($values['due_date'] ?? '') !== '' ? $values['due_date'] : $defaultDue;
                if ($existing) {
                    if ($qty > 0) {
                        $sub = Request::create('/', 'POST', [
                            'quantity' => $qty,
                            'unit_cost' => $cost > 0 ? $cost : null,
                            'payment_status' => $payment,
                            'due_date' => $payment === 'credit' ? $due : null,
                            'supplier_id' => $supplierId,
                        ]);
                        $sub->setUserResolver(fn () => $request->user());
                        $this->restock($sub, $existing);
                    }
                    $restocked++;
                    continue;
                }
                $booksPurchase = $qty > 0 && $cost > 0;
                $product = Product::create([
                    'name' => $name,
                    'sku' => ($values['sku'] ?? '') !== '' ? $values['sku'] : null,
                    'barcode' => $barcode,
                    'category' => ($values['category'] ?? '') !== '' ? $values['category'] : null,
                    'subcategory' => ($values['subcategory'] ?? '') !== '' ? $values['subcategory'] : null,
                    'size' => ($values['size'] ?? '') !== '' ? $values['size'] : null,
                    'color' => ($values['color'] ?? '') !== '' ? $values['color'] : null,
                    'price' => ($values['price'] ?? '') !== '' ? $values['price'] : 0,
                    'cost_price' => $cost,
                    'stock_qty' => $booksPurchase ? 0 : max(0, $qty),
                    'description' => ($values['description'] ?? '') !== '' ? $values['description'] : null,
                ]);
                PartCategory::remember($product->category, $product->subcategory);
                if ($booksPurchase) {
                    $sub = Request::create('/', 'POST', [
                        'quantity' => $qty,
                        'unit_cost' => $cost,
                        'payment_status' => $payment,
                        'due_date' => $payment === 'credit' ? $due : null,
                        'supplier_id' => $supplierId,
                    ]);
                    $sub->setUserResolver(fn () => $request->user());
                    $this->restock($sub, $product->refresh());
                }
                $created++;
            }
        });

        return response()->json(['created' => $created, 'restocked' => $restocked]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $this->normalizeImageUploads($request);
        if ($request->input('barcode') === '') {
            $request->merge(['barcode' => null]);
        }
        if ($request->input('sku') === '') {
            $request->merge(['sku' => null]);
        }
        $data = $request->validate([
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'badge' => ['nullable', 'string', 'max:30'],
            'price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['image', 'max:5120'],
        ]);
        unset($data['images']);
        foreach (['category', 'subcategory', 'size', 'color', 'sku', 'barcode'] as $field) {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') {
                $data[$field] = null;
            }
        }
        if (array_key_exists('category', $data) || array_key_exists('subcategory', $data)) {
            PartCategory::remember($data['category'] ?? $product?->category, $data['subcategory'] ?? $product?->subcategory);
        }

        return $data;
    }

    /**
     * @return list<array{size: string, stock_qty: int}>|null
     */
    private function expandedSizes(Request $request): ?array
    {
        $scale = $request->input('size_scale');
        if (! is_string($scale) || $scale === '') {
            return null;
        }
        if (! in_array($scale, ['apparel', 'jeans', 'footwear', 'one'], true)) {
            abort(422, 'Choose a size run: apparel, jeans, footwear, or one size.');
        }
        $quantities = $request->input('size_quantities', []);
        if (! is_array($quantities)) {
            $quantities = [];
        }
        $rows = [];
        foreach (ClothingSizes::forScale($scale) as $size) {
            $rows[] = [
                'size' => $size,
                'stock_qty' => max(0, (int) ($quantities[$size] ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $totals
     * @return list<array{size: string, qty: int}>
     */
    private function sizeRows(array $totals): array
    {
        $rows = [];
        foreach ($totals as $size => $qty) {
            $rows[] = ['size' => (string) $size, 'qty' => (int) $qty];
        }

        return $rows;
    }

    private function storeImages(Request $request, Product $product): void
    {
        $this->normalizeImageUploads($request);
        if (! $request->hasFile('images')) {
            return;
        }
        $files = $request->file('images');
        $files = is_array($files) ? $files : [$files];
        $images = $product->images ?? [];
        foreach ($files as $image) {
            if ($image) {
                $images[] = $image->store('products', 'public');
            }
        }
        $product->update(['images' => array_slice($images, 0, 5)]);
    }

    private function normalizeImageUploads(Request $request): void
    {
        if ($request->hasFile('images') === false && $request->files->has('images')) {
            $request->files->remove('images');
        }
        if (! $request->hasFile('images')) {
            $request->request->remove('images');
            $request->files->remove('images');

            return;
        }
        $files = $request->file('images');
        if (! is_array($files)) {
            $request->files->set('images', [$files]);
        }
    }

    private function clothingTenant(Request $request): bool
    {
        $type = BusinessTypes::normalizeLegacy((string) ($request->user()?->tenant?->business_type ?? ''));

        return $type === BusinessTypes::CLOTHING;
    }

    /**
     * The same barcode can cover every size of one garment. It cannot be reused
     * by a different garment, or by the same size twice.
     *
     * @param  list<int>  $ignoreIds
     */
    private function assertBarcode(string $barcode, ?string $size, string $name, ?string $color, ?string $category, array $ignoreIds = []): void
    {
        $taken = Product::query()
            ->where('barcode', $barcode)
            ->when($ignoreIds !== [], fn ($query) => $query->whereNotIn('id', $ignoreIds))
            ->get(['id', 'name', 'color', 'category', 'size']);

        foreach ($taken as $row) {
            $sameSize = (string) ($row->size ?? '') === (string) ($size ?? '');
            $sameStyle = $row->name === $name
                && (string) ($row->color ?? '') === (string) ($color ?? '')
                && (string) ($row->category ?? '') === (string) ($category ?? '');
            if ($sameSize || ! $sameStyle) {
                throw ValidationException::withMessages([
                    'barcode' => ['That barcode is already used by another item.'],
                ]);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function styleIds(?string $name, ?string $color, ?string $category): array
    {
        return $this->styleQuery($name, $color, $category)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array{name: string, color: ?string, category: ?string}  $before
     */
    private function syncStyle(Product $product, array $before): void
    {
        $siblings = $this->styleQuery($before['name'], $before['color'], $before['category'])
            ->whereKeyNot($product->id)
            ->get();

        foreach ($siblings as $sibling) {
            if ((string) ($sibling->size ?? '') === (string) ($product->size ?? '')) {
                continue;
            }
            $sibling->update([
                'name' => $product->name,
                'color' => $product->color,
                'category' => $product->category,
                'subcategory' => $product->subcategory,
                'barcode' => $product->barcode,
                'images' => $product->images,
            ]);
        }
    }

    private function styleQuery(?string $name, ?string $color, ?string $category)
    {
        return Product::query()
            ->where('name', (string) $name)
            ->when($color === null || $color === '', fn ($query) => $query->whereNull('color'), fn ($query) => $query->where('color', $color))
            ->when($category === null || $category === '', fn ($query) => $query->whereNull('category'), fn ($query) => $query->where('category', $category));
    }
}
