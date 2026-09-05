<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Part;
use App\Models\Product;
use App\Support\BranchContext;
use Illuminate\Validation\ValidationException;

class BranchInventory
{
    public static bool $mutating = false;

    public static function partQty(int $partId, ?int $branchId = null): int
    {
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor(Part::withoutGlobalScopes()->find($partId)?->tenant_id);

        if (! $branchId) {
            return (int) (Part::withoutGlobalScopes()->find($partId)?->getRawOriginal('stock_qty') ?? 0);
        }

        return (int) (BranchStock::query()
            ->where('branch_id', $branchId)
            ->where('part_id', $partId)
            ->value('qty') ?? 0);
    }

    public static function productQty(int $productId, ?int $branchId = null): int
    {
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor(Product::withoutGlobalScopes()->find($productId)?->tenant_id);

        if (! $branchId) {
            return (int) (Product::withoutGlobalScopes()->find($productId)?->getRawOriginal('stock_qty') ?? 0);
        }

        return (int) (BranchStock::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->value('qty') ?? 0);
    }

    public static function takePart(Part $part, int $quantity, ?int $branchId = null): void
    {
        if ($quantity <= 0) {
            return;
        }
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($part->tenant_id);
        abort_unless($branchId, 422, 'Select a shop before taking stock.');

        self::$mutating = true;
        try {
            $row = self::lockPartRow($part, $branchId);
            if ((int) $row->qty < $quantity) {
                throw ValidationException::withMessages(['quantity' => ['Insufficient stock for this part at this shop.']]);
            }
            $row->decrement('qty', $quantity);
            self::syncPartTotal($part);
            $part->refresh();
        } finally {
            self::$mutating = false;
        }
    }

    public static function returnPart(Part $part, int $quantity, ?int $branchId = null): void
    {
        if ($quantity <= 0) {
            return;
        }
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($part->tenant_id);
        if (! $branchId) {
            $part->increment('stock_qty', $quantity);
            $part->refresh();

            return;
        }

        self::$mutating = true;
        try {
            $row = self::lockPartRow($part, $branchId);
            $row->increment('qty', $quantity);
            self::syncPartTotal($part);
            $part->refresh();
        } finally {
            self::$mutating = false;
        }
    }

    public static function addPart(Part $part, int $quantity, ?int $branchId = null): void
    {
        self::returnPart($part, $quantity, $branchId);
    }

    public static function takeProduct(Product $product, int $quantity, ?int $branchId = null): void
    {
        if ($quantity <= 0) {
            return;
        }
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($product->tenant_id);
        abort_unless($branchId, 422, 'Select a shop before taking stock.');

        self::$mutating = true;
        try {
            $row = self::lockProductRow($product, $branchId);
            if ((int) $row->qty < $quantity) {
                throw ValidationException::withMessages(['quantity' => ["Insufficient stock for {$product->name} at this shop."]]);
            }
            $row->decrement('qty', $quantity);
            self::syncProductTotal($product);
            $product->refresh();
        } finally {
            self::$mutating = false;
        }
    }

    public static function returnProduct(Product $product, int $quantity, ?int $branchId = null): void
    {
        if ($quantity <= 0) {
            return;
        }
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($product->tenant_id);
        if (! $branchId) {
            $product->increment('stock_qty', $quantity);
            $product->refresh();

            return;
        }

        self::$mutating = true;
        try {
            $row = self::lockProductRow($product, $branchId);
            $row->increment('qty', $quantity);
            self::syncProductTotal($product);
            $product->refresh();
        } finally {
            self::$mutating = false;
        }
    }

    public static function setPartQty(Part $part, int $quantity, ?int $branchId = null): void
    {
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($part->tenant_id);
        if (! $branchId) {
            $part->update(['stock_qty' => $quantity]);

            return;
        }

        self::$mutating = true;
        try {
            $row = self::lockPartRow($part, $branchId);
            $row->update(['qty' => $quantity]);
            self::syncPartTotal($part);
            $part->refresh();
        } finally {
            self::$mutating = false;
        }
    }

    public static function setProductQty(Product $product, int $quantity, ?int $branchId = null): void
    {
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($product->tenant_id);
        if (! $branchId) {
            $product->update(['stock_qty' => $quantity]);

            return;
        }

        self::$mutating = true;
        try {
            $row = self::lockProductRow($product, $branchId);
            $row->update(['qty' => $quantity]);
            self::syncProductTotal($product);
            $product->refresh();
        } finally {
            self::$mutating = false;
        }
    }

    public static function transferPart(Part $part, int $fromBranchId, int $toBranchId, int $quantity): void
    {
        abort_if($fromBranchId === $toBranchId, 422, 'Choose two different shops.');
        abort_if($quantity <= 0, 422, 'Transfer quantity must be greater than zero.');

        self::$mutating = true;
        try {
            $from = self::lockPartRow($part, $fromBranchId);
            if ((int) $from->qty < $quantity) {
                throw ValidationException::withMessages(['quantity' => ['Not enough stock at the sending shop.']]);
            }
            $to = self::lockPartRow($part, $toBranchId);
            $from->decrement('qty', $quantity);
            $to->increment('qty', $quantity);
            self::syncPartTotal($part);
        } finally {
            self::$mutating = false;
        }
    }

    public static function transferProduct(Product $product, int $fromBranchId, int $toBranchId, int $quantity): void
    {
        abort_if($fromBranchId === $toBranchId, 422, 'Choose two different shops.');
        abort_if($quantity <= 0, 422, 'Transfer quantity must be greater than zero.');

        self::$mutating = true;
        try {
            $from = self::lockProductRow($product, $fromBranchId);
            if ((int) $from->qty < $quantity) {
                throw ValidationException::withMessages(['quantity' => ['Not enough stock at the sending shop.']]);
            }
            $to = self::lockProductRow($product, $toBranchId);
            $from->decrement('qty', $quantity);
            $to->increment('qty', $quantity);
            self::syncProductTotal($product);
        } finally {
            self::$mutating = false;
        }
    }

    public static function seedPart(Part $part, int $quantity, ?int $branchId = null): void
    {
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($part->tenant_id);
        if (! $branchId || ! $part->tenant_id) {
            return;
        }

        self::$mutating = true;
        try {
            BranchStock::query()->firstOrCreate(
                ['branch_id' => $branchId, 'part_id' => $part->id],
                ['tenant_id' => $part->tenant_id, 'qty' => max(0, $quantity)],
            );
            self::syncPartTotal($part);
        } finally {
            self::$mutating = false;
        }
    }

    public static function seedProduct(Product $product, int $quantity, ?int $branchId = null): void
    {
        $branchId ??= BranchContext::id() ?? Branch::defaultIdFor($product->tenant_id);
        if (! $branchId || ! $product->tenant_id) {
            return;
        }

        self::$mutating = true;
        try {
            BranchStock::query()->firstOrCreate(
                ['branch_id' => $branchId, 'product_id' => $product->id],
                ['tenant_id' => $product->tenant_id, 'qty' => max(0, $quantity)],
            );
            self::syncProductTotal($product);
        } finally {
            self::$mutating = false;
        }
    }

    public static function overlayPart(Part $part): Part
    {
        $branchId = BranchContext::id();
        if ($branchId) {
            $part->setAttribute('stock_qty', self::partQty($part->id, $branchId));
        }

        return $part;
    }

    public static function overlayProduct(Product $product): Product
    {
        $branchId = BranchContext::id();
        if ($branchId) {
            $product->setAttribute('stock_qty', self::productQty($product->id, $branchId));
        }

        return $product;
    }

    private static function lockPartRow(Part $part, int $branchId): BranchStock
    {
        $row = BranchStock::query()
            ->where('branch_id', $branchId)
            ->where('part_id', $part->id)
            ->lockForUpdate()
            ->first();

        if ($row) {
            return $row;
        }

        return BranchStock::query()->create([
            'tenant_id' => $part->tenant_id,
            'branch_id' => $branchId,
            'part_id' => $part->id,
            'qty' => 0,
        ]);
    }

    private static function lockProductRow(Product $product, int $branchId): BranchStock
    {
        $row = BranchStock::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($row) {
            return $row;
        }

        return BranchStock::query()->create([
            'tenant_id' => $product->tenant_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'qty' => 0,
        ]);
    }

    private static function syncPartTotal(Part $part): void
    {
        $total = (int) BranchStock::query()->where('part_id', $part->id)->sum('qty');
        Part::withoutGlobalScopes()->whereKey($part->id)->update(['stock_qty' => $total]);
    }

    private static function syncProductTotal(Product $product): void
    {
        $total = (int) BranchStock::query()->where('product_id', $product->id)->sum('qty');
        Product::withoutGlobalScopes()->whereKey($product->id)->update(['stock_qty' => $total]);
    }
}
