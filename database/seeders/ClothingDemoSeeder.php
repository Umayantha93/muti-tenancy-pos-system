<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Product;
use App\Models\RetailSale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillCalculator;
use App\Support\BranchContext;
use App\Support\BusinessTypes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ClothingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Feature::query()->where('key', 'customer_display')->doesntExist()) {
            $this->call(FeatureSeeder::class);
        }

        $tenant = $this->ensureTenant();
        $owner = User::query()->where('tenant_id', $tenant->id)->where('role', 'business_owner')->first();
        if (! $owner) {
            return;
        }

        Auth::login($owner);
        $branch = Branch::ensureDefault($tenant);
        BranchContext::set((int) $branch->id);

        try {
            if (Product::query()->where('sku', 'VT-MEN-01-XS')->exists()) {
                return;
            }

            if (! $tenant->vat_registered) {
                $tenant->update(['vat_registered' => true, 'vat_rate' => 8.5]);
            }
            if ($branch->name === 'Main') {
                $branch->update(['name' => 'Flagship']);
            }
            $this->enableCustomerDisplay($tenant);

            DB::transaction(function () use ($owner) {
                $customers = $this->seedCustomers();
                $products = $this->seedProducts();
                $this->seedSales($owner, $customers, $products);
            });
        } finally {
            Auth::logout();
            BranchContext::clear();
        }
    }

    private function ensureTenant(): Tenant
    {
        $tenant = Tenant::query()->where('owner_email', 'owner@clothing.lk')->first();
        if ($tenant) {
            return $tenant;
        }

        $tenant = Tenant::query()->create([
            'business_name' => 'Thread & Co',
            'business_type' => BusinessTypes::CLOTHING,
            'owner_name' => 'Boutique Owner',
            'owner_phone' => '0777654321',
            'owner_phones' => [['label' => 'Primary', 'number' => '0777654321']],
            'owner_email' => 'owner@clothing.lk',
            'contact_email' => 'owner@clothing.lk',
            'contact_phone' => '0777654321',
            'contact_phones' => [['label' => 'Business', 'number' => '0777654321']],
            'address' => '12 Galle Road, Colombo 03',
            'status' => 'active',
            'plan' => 'retail-pro',
            'payment_plan' => 'yearly',
            'plan_amount' => 120000,
        ]);

        $keys = BusinessTypes::defaults(BusinessTypes::CLOTHING);
        $tenant->features()->sync(
            Feature::query()->whereIn('key', $keys)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['is_enabled' => true]])
                ->all()
        );

        $branch = Branch::ensureDefault($tenant);
        User::query()->updateOrCreate(['email' => 'owner@clothing.lk'], [
            'tenant_id' => $tenant->id,
            'name' => 'Boutique Owner',
            'password' => Hash::make('password'),
            'role' => 'business_owner',
            'status' => 'active',
            'home_branch_id' => $branch->id,
            'last_branch_id' => $branch->id,
        ]);

        return $tenant;
    }

    private function enableCustomerDisplay(Tenant $tenant): void
    {
        $feature = Feature::query()->where('key', 'customer_display')->first();
        if (! $feature) {
            return;
        }
        if ($tenant->features()->where('features.id', $feature->id)->exists()) {
            $tenant->features()->updateExistingPivot($feature->id, ['is_enabled' => true]);

            return;
        }
        $tenant->features()->attach($feature->id, ['is_enabled' => true]);
    }

    /**
     * @return list<Customer>
     */
    private function seedCustomers(): array
    {
        $customers = [];
        for ($i = 1; $i <= 80; $i++) {
            $customers[] = Customer::query()->create([
                'name' => fake()->name(),
                'phone' => '0776'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'is_vip' => $i <= 16,
                'sms_opt_in' => true,
            ]);
        }

        return $customers;
    }

    /**
     * @return list<array{product: Product, price: float, cost: float}>
     */
    private function seedProducts(): array
    {
        $apparel = ['XS', 'S', 'M', 'L', 'XL'];
        $shoes = ['EU 39', 'EU 40', 'EU 41', 'EU 42', 'EU 43'];
        $styles = [
            ['Men', 'Classic Cotton Oxford Shirt', 'BESTSELLER', 'Navy', 4990, 2100, $apparel],
            ['Men', 'Slim Chambray Shirt', null, 'Blue', 4590, 1900, $apparel],
            ['Men', 'Grey Selvedge Jeans', 'TRENDING', 'Grey', 8990, 4200, $apparel],
            ['Men', 'Merino Crew Knit', null, 'Charcoal', 7490, 3100, $apparel],
            ['Men', 'Linen Camp Collar Shirt', 'TRENDING', 'Sand', 6290, 2400, $apparel],
            ['Men', 'Tailored Chino Trouser', null, 'Stone', 6990, 2800, $apparel],
            ['Men', 'Heavyweight Hoodie', 'BESTSELLER', 'Black', 5490, 2200, $apparel],
            ['Men', 'Wool Overshirt', null, 'Olive', 9990, 4600, $apparel],
            ['Women', 'High-Waist Slim Denim Jeans', 'TRENDING', 'Indigo', 6950, 2900, $apparel],
            ['Women', 'Floral Print Summer Dress', 'SALE', 'Red', 7990, 2600, $apparel],
            ['Women', 'Silk Bias Slip Dress', null, 'Champagne', 12990, 5400, $apparel],
            ['Women', 'Cotton Poplin Blouse', null, 'White', 4290, 1600, $apparel],
            ['Women', 'Pleated Midi Skirt', 'BESTSELLER', 'Black', 5890, 2100, $apparel],
            ['Women', 'Linen Wide-Leg Trouser', null, 'Cream', 7490, 3000, $apparel],
            ['Women', 'Ribbed Knit Cardigan', null, 'Camel', 6590, 2500, $apparel],
            ['Women', 'Satin Cami Top', 'SALE', 'Blush', 3990, 1400, $apparel],
            ['Kids', 'Kids Graphic Cotton Tee', null, 'Blue', 1990, 700, $apparel],
            ['Kids', 'Kids Denim Short', null, 'Blue', 2490, 900, $apparel],
            ['Kids', 'Kids Striped Tee', 'TRENDING', 'Navy', 1790, 600, $apparel],
            ['Kids', 'Kids Jogger', null, 'Grey', 2990, 1100, $apparel],
            ['Accessories', 'Genuine Leather Minimalist Belt', null, 'Tan', 3450, 1200, ['One']],
            ['Accessories', 'Canvas Tote', 'BESTSELLER', 'Natural', 2490, 800, ['One']],
            ['Accessories', 'Wool Scarf', null, 'Grey', 3290, 1100, ['One']],
            ['Accessories', 'Leather Card Holder', null, 'Black', 2890, 900, ['One']],
            ['Accessories', 'Cotton Cap', null, 'White', 1890, 500, ['One']],
            ['Accessories', 'Metal Frame Sunglasses', 'SALE', 'Gold', 4590, 1600, ['One']],
            ['Footwear', 'Urban Canvas Low-Top Sneakers', null, 'Burgundy', 6400, 2800, $shoes],
            ['Footwear', 'Leather Loafer', null, 'Brown', 11900, 5200, $shoes],
            ['Footwear', 'Running Knit Trainer', 'TRENDING', 'White', 8900, 3900, $shoes],
            ['Footwear', 'Sandal Slide', null, 'Black', 3490, 1200, $shoes],
            ['Footwear', 'Chelsea Boot', null, 'Black', 14900, 6800, $shoes],
            ['Footwear', 'Lightweight Trench Coat', 'CLEARANCE', 'Stone', 11800, 4900, $apparel],
        ];

        $rows = [];
        $n = 1;
        foreach ($styles as [$category, $name, $badge, $color, $price, $cost, $sizes]) {
            $code = strtoupper(substr($category, 0, 3));
            foreach ($sizes as $size) {
                $sku = sprintf('VT-%s-%02d-%s', $code, $n, str_replace(' ', '', $size));
                $onHand = $n % 7 === 0 ? random_int(2, 8) : random_int(12, 40);
                $product = Product::query()->create([
                    'name' => $name,
                    'sku' => $sku,
                    'barcode' => '890'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
                    'category' => $category === 'Footwear' && $name === 'Lightweight Trench Coat' ? 'Sale' : $category,
                    'size' => $size,
                    'color' => $color,
                    'badge' => $badge,
                    'price' => $price,
                    'cost_price' => $cost,
                    'stock_qty' => $onHand,
                    'images' => ["https://picsum.photos/seed/{$sku}/480/640"],
                    'active' => true,
                ]);
                $rows[] = ['product' => $product->fresh(), 'price' => (float) $price, 'cost' => (float) $cost];
                $n++;
            }
        }

        return $rows;
    }

    /**
     * @param  list<Customer>  $customers
     * @param  list<array{product: Product, price: float, cost: float}>  $products
     */
    private function seedSales(User $owner, array $customers, array $products): void
    {
        $calculator = app(BillCalculator::class);
        $methods = ['cash', 'cash', 'cash', 'card', 'card', 'qr', 'credit'];
        $count = count($products);

        for ($i = 1; $i <= 200; $i++) {
            $customer = $customers[array_rand($customers)];
            $when = $i <= 16
                ? now()->setTime(random_int(9, 18), random_int(0, 59))
                : now()->subDays(random_int(1, 45))->setTime(random_int(9, 18), random_int(0, 59));
            $method = $methods[array_rand($methods)];
            $sale = RetailSale::query()->create([
                'customer_id' => $customer->id,
                'status' => 'completed',
                'created_by' => $owner->id,
            ]);
            $bill = Bill::query()->create([
                'bill_number' => 'SALE-D-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'admission_date' => $when->toDateString(),
                'source_type' => RetailSale::class,
                'source_id' => $sale->id,
                'created_by' => $owner->id,
            ]);

            $subtotal = 0.0;
            $picked = [];
            $lines = random_int(1, 3);
            for ($line = 0; $line < $lines; $line++) {
                $row = $products[random_int(0, $count - 1)];
                if (isset($picked[$row['product']->id])) {
                    continue;
                }
                $picked[$row['product']->id] = true;
                $qty = random_int(1, 2);
                $lineTotal = round($row['price'] * $qty, 2);
                BillItem::query()->create([
                    'bill_id' => $bill->id,
                    'type' => 'product',
                    'description' => trim($row['product']->name.' '.$row['product']->size.' '.$row['product']->color),
                    'quantity' => $qty,
                    'unit_price' => $row['price'],
                    'purchase_unit_cost' => $row['cost'],
                    'line_total' => $lineTotal,
                ]);
                $subtotal += $lineTotal;
            }

            if ($subtotal <= 0) {
                $sale->delete();
                $bill->delete();
                continue;
            }

            if ($customer->is_vip) {
                BillItem::query()->create([
                    'bill_id' => $bill->id,
                    'type' => 'discount',
                    'description' => 'VIP 10%',
                    'quantity' => 1,
                    'unit_price' => round($subtotal * 0.10, 2),
                    'line_total' => round($subtotal * 0.10, 2),
                ]);
            }

            $calculator->recalculate($bill);
            if ($method !== 'credit') {
                BillPayment::query()->create([
                    'bill_id' => $bill->id,
                    'amount' => $bill->balance_due,
                    'method' => $method,
                    'paid_at' => $when,
                    'received_by' => $owner->id,
                ]);
                $calculator->recalculate($bill);
            }

            $bill->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
            $sale->forceFill(['bill_id' => $bill->id, 'created_at' => $when, 'updated_at' => $when])->saveQuietly();
        }
    }
}
