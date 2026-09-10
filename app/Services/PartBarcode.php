<?php

namespace App\Services;

use App\Models\Part;

class PartBarcode
{
    /**
     * Assign a unique internal barcode when the part has none.
     */
    public static function ensure(Part $part): Part
    {
        $current = trim((string) ($part->barcode ?? ''));
        if ($current !== '') {
            return $part;
        }

        $part->barcode = self::uniqueForTenant((int) $part->tenant_id);
        $part->save();

        return $part->refresh();
    }

    /**
     * Code128-friendly internal barcode unique within the tenant.
     */
    public static function uniqueForTenant(?int $tenantId = null, ?string $exclude = null): string
    {
        $tenantId = $tenantId ?? (int) (auth()->user()?->tenant_id ?? 0);

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $candidate = 'POS'.strtoupper(bin2hex(random_bytes(5)));
            if ($exclude !== null && strcasecmp($candidate, $exclude) === 0) {
                continue;
            }

            $query = Part::query()->where('barcode', $candidate);
            if ($tenantId > 0) {
                $query->where('tenant_id', $tenantId);
            }

            if (! $query->exists()) {
                return $candidate;
            }
        }

        return 'POS'.strtoupper(bin2hex(random_bytes(8)));
    }

    public static function namesMatch(?string $a, ?string $b): bool
    {
        return self::normalizeName($a) === self::normalizeName($b);
    }

    public static function normalizeName(?string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim((string) $value))) ?? '';
    }

    public static function noteSupplierBarcode(?string $description, string $supplierBarcode): string
    {
        $note = 'Supplier barcode: '.$supplierBarcode;
        $description = trim((string) $description);
        if ($description === '') {
            return $note;
        }
        if (stripos($description, $note) !== false) {
            return $description;
        }

        return $description."\n".$note;
    }
}
