<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\PartCategory;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Services\BranchInventory;
use App\Support\ClothingSizes;
use App\Support\InventoryCosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
            $created = [];
            foreach ($expanded as $index => $row) {
                $product = Product::create([
                    ...$base,
                    'size' => $row['size'],
                    'stock_qty' => $row['stock_qty'],
                    'sku' => $sku ? $sku.'-'.str_replace(' ', '', $row['size']) : null,
                    'barcode' => $index === 0 ? $barcode : null,
                ]);
                $this->storeImages($request, $product);
                $created[] = $product->refresh();
            }

            return response()->json(['id' => $created[0]->id, 'products' => $created], 201);
        }

        $data = $this->validated($request);
        $product = Product::create($data);
        $this->storeImages($request, $product);

        return response()->json($product->refresh(), 201);
    }

    public function sizeStock(): JsonResponse
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
        $product->update($data);
        $this->storeImages($request, $product);

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
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['nullable', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'expense_date' => ['nullable', 'date'],
        ]);

        $count = DB::transaction(function () use ($data, $request) {
            foreach ($data['items'] as $row) {
                $product = Product::query()->whereKey($row['product_id'])->firstOrFail();
                $sub = Request::create('/', 'POST', [
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'] ?? null,
                    'payment_status' => $data['payment_status'] ?? 'paid',
                    'due_date' => $data['due_date'] ?? null,
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'expense_date' => $data['expense_date'] ?? null,
                ]);
                $sub->setUserResolver(fn () => $request->user());
                $this->restock($sub, $product);
            }

            return count($data['items']);
        });

        return response()->json(['updated' => $count]);
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
            ['A matching barcode adds the stock quantity to the existing item.'],
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
                $existing = $barcode ? Product::query()->where('barcode', $barcode)->first() : null;
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
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->where('tenant_id', $request->user()->tenant_id)->ignore($product?->id)],
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
}
