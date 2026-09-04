<?php

namespace App\Support;

class BusinessTypes
{
    public const GARAGE = 'garage';

    public const TYRE = 'tyre';

    public const DEVICE_REPAIR = 'device_repair';

    public const PAINT = 'paint';

    public const PHOTOGRAPHY = 'photography';

    public const CLOTHING = 'clothing';

    public const SALON = 'salon';

    public const COTTAGE = 'cottage';

    public const STORE = 'store';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::GARAGE, self::TYRE, self::DEVICE_REPAIR, self::PAINT,
            self::PHOTOGRAPHY, self::CLOTHING, self::SALON, self::COTTAGE,
            self::STORE,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function featureMatrix(): array
    {
        $shared = ['customers', 'billing', 'bill_sms', 'bill_profits', 'employees_management', 'attendance', 'payroll', 'balance_sheet', 'reports'];

        $garageFamily = array_merge(['admit_vehicle', 'parts_inventory', 'suppliers'], $shared);
        $retailFamily = array_merge(['retail_pos', 'product_catalog', 'suppliers'], $shared);
        $storeFamily = array_merge(['parts_inventory', 'suppliers', 'repair_bills'], $shared);

        return [
            self::GARAGE => $garageFamily,
            self::TYRE => $garageFamily,
            self::DEVICE_REPAIR => $garageFamily,
            self::PAINT => $garageFamily,
            self::PHOTOGRAPHY => array_merge(['photo_bookings', 'photo_packages'], $shared),
            self::CLOTHING => $retailFamily,
            self::SALON => array_merge(['photo_bookings', 'photo_packages', 'retail_pos', 'product_catalog'], $shared),
            self::COTTAGE => array_merge(['cottage_rooms', 'cottage_stays'], $shared),
            self::STORE => $storeFamily,
        ];
    }

    /**
     * Optional modules the super-admin can enable later. Not ticked on onboard.
     *
     * @return list<string>
     */
    public static function optionalFeatures(string $type): array
    {
        return match ($type) {
            self::STORE => ['repair_bills'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function defaults(string $type): array
    {
        $all = self::featureMatrix()[$type] ?? ['customers', 'billing', 'balance_sheet', 'reports'];

        return array_values(array_diff($all, self::optionalFeatures($type)));
    }

    /**
     * @return list<string>
     */
    public static function featuresForType(?string $type): array
    {
        if ($type && isset(self::featureMatrix()[$type])) {
            return self::featureMatrix()[$type];
        }

        return array_values(array_unique(array_merge(...array_values(self::featureMatrix()))));
    }

    public static function billPrefix(string $type): string
    {
        return match ($type) {
            self::PHOTOGRAPHY, self::SALON => 'ORD',
            self::CLOTHING, self::STORE => 'SALE',
            self::COTTAGE => 'STAY',
            self::DEVICE_REPAIR => 'REP',
            default => 'JOB',
        };
    }

    public static function normalizeLegacy(string $type): string
    {
        return match ($type) {
            'shop', 'supermarket' => self::CLOTHING,
            'bike', 'three_wheel', 'auto_ac', 'detailing', 'tyre_shop' => self::TYRE,
            'phone_repair', 'appliance' => self::DEVICE_REPAIR,
            'communications', 'phone_shop', 'parts_shop', 'mobile_shop' => self::STORE,
            'spa', 'barber' => self::SALON,
            default => in_array($type, self::all(), true) ? $type : self::GARAGE,
        };
    }

    /**
     * Display-only plan labels for tenants.plan (does not gate features).
     *
     * @return list<string>
     */
    public static function plans(): array
    {
        return [
            'garage-pro',
            'paint-pro',
            'studio-pro',
            'retail-pro',
            'store-pro',
            'stay-pro',
            'salon-pro',
            'repair-pro',
            'Growth',
            'Trial',
            'Custom',
        ];
    }

    /**
     * @return list<string>
     */
    public static function paymentPlans(): array
    {
        return ['monthly', 'yearly'];
    }

    public static function defaultPlan(string $businessType): string
    {
        return match ($businessType) {
            self::PHOTOGRAPHY => 'studio-pro',
            self::CLOTHING => 'retail-pro',
            self::STORE => 'store-pro',
            self::COTTAGE => 'stay-pro',
            self::SALON => 'salon-pro',
            self::DEVICE_REPAIR => 'repair-pro',
            self::PAINT => 'paint-pro',
            default => 'garage-pro',
        };
    }

    /**
     * Bill line types shown in the UI for each business.
     *
     * @return list<array{value: string, label: string, kind: string, allow_qty?: bool}>
     */
    public static function billItemTypes(string $businessType): array
    {
        return match ($businessType) {
            self::SALON => [
                ['value' => 'session', 'label' => 'Service', 'kind' => 'charge'],
                ['value' => 'package', 'label' => 'Package', 'kind' => 'charge'],
                ['value' => 'product', 'label' => 'Retail product', 'kind' => 'charge', 'allow_qty' => true],
                ['value' => 'addon', 'label' => 'Add-on', 'kind' => 'charge'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            self::PHOTOGRAPHY => [
                ['value' => 'session', 'label' => 'Session / shoot', 'kind' => 'charge'],
                ['value' => 'package', 'label' => 'Package', 'kind' => 'charge'],
                ['value' => 'print', 'label' => 'Prints / products', 'kind' => 'charge', 'allow_qty' => true],
                ['value' => 'addon', 'label' => 'Add-on', 'kind' => 'charge'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            self::CLOTHING => [
                ['value' => 'product', 'label' => 'Clothing item', 'kind' => 'charge', 'allow_qty' => true],
                ['value' => 'alteration', 'label' => 'Alteration', 'kind' => 'charge'],
                ['value' => 'charge', 'label' => 'Other charge', 'kind' => 'charge'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            self::STORE => [
                ['value' => 'part', 'label' => 'Item', 'kind' => 'stock', 'allow_qty' => true],
                ['value' => 'labor', 'label' => 'Repair', 'kind' => 'charge'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            self::COTTAGE => [
                ['value' => 'room', 'label' => 'Room night', 'kind' => 'charge', 'allow_qty' => true],
                ['value' => 'amenity', 'label' => 'Amenity / extras', 'kind' => 'charge'],
                ['value' => 'meal', 'label' => 'Meals', 'kind' => 'charge', 'allow_qty' => true],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            self::TYRE => [
                ['value' => 'labor', 'label' => 'Labor', 'kind' => 'charge'],
                ['value' => 'part', 'label' => 'Tyre / part', 'kind' => 'stock'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            self::DEVICE_REPAIR => [
                ['value' => 'labor', 'label' => 'Labor', 'kind' => 'charge'],
                ['value' => 'part', 'label' => 'Spare', 'kind' => 'stock'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            self::PAINT => [
                ['value' => 'labor', 'label' => 'Labor', 'kind' => 'charge', 'allow_qty' => true],
                ['value' => 'part', 'label' => 'Color / material', 'kind' => 'stock'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
            default => [
                ['value' => 'labor', 'label' => 'Labor', 'kind' => 'charge', 'allow_qty' => true],
                ['value' => 'part', 'label' => 'Inventory', 'kind' => 'stock'],
                ['value' => 'discount', 'label' => 'Discount', 'kind' => 'discount'],
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function chargeItemTypes(): array
    {
        return [
            'labor', 'charge', 'part', 'service', 'service_addon',
            'session', 'package', 'print', 'addon',
            'product', 'alteration',
            'room', 'amenity', 'meal',
        ];
    }

    /**
     * @return list<string>
     */
    public static function discountItemTypes(): array
    {
        return ['discount'];
    }

    /**
     * @return list<string>
     */
    public static function allowedBillItemTypes(string $businessType): array
    {
        $values = collect(self::billItemTypes($businessType))->pluck('value')->all();
        if (self::usesVehicleJobs($businessType) || self::usesStoreCounter($businessType)) {
            $values[] = 'customer_part';
        }
        if (self::usesVehicleJobs($businessType)) {
            $values[] = 'charge';
            $values[] = 'service_addon';
        }

        return array_values(array_unique($values));
    }

    public static function usesVehicleJobs(string $type): bool
    {
        return in_array($type, [self::GARAGE, self::TYRE, self::DEVICE_REPAIR, self::PAINT], true);
    }

    public static function usesLaborCatalog(string $type): bool
    {
        return in_array($type, [self::GARAGE, self::PAINT], true);
    }

    public static function usesServiceAddonWorkspace(string $type): bool
    {
        return in_array($type, [self::GARAGE, self::PAINT], true);
    }

    public static function usesStoreCounter(string $type): bool
    {
        return $type === self::STORE;
    }

    public static function billItemKind(string $type): string
    {
        if (in_array($type, self::discountItemTypes(), true)) {
            return 'discount';
        }
        if (in_array($type, ['part', 'customer_part'], true)) {
            return 'stock';
        }

        return 'charge';
    }

    /**
     * Job card / bill line order: inventory parts, other charges, labor, then discount.
     */
    public static function billItemDisplayOrderSql(string $column = 'bill_items.type'): string
    {
        return "CASE {$column}
            WHEN 'part' THEN 1
            WHEN 'customer_part' THEN 1
            WHEN 'labor' THEN 3
            WHEN 'discount' THEN 4
            ELSE 2
        END";
    }

    public static function billItemLabel(string $type): string
    {
        foreach (self::all() as $businessType) {
            foreach (self::billItemTypes($businessType) as $item) {
                if ($item['value'] === $type) {
                    return $item['label'];
                }
            }
        }

        return match ($type) {
            'customer_part' => 'Customer part',
            'service' => 'Service',
            'service_addon' => 'Service',
            'charge' => 'Service / charge',
            default => str($type)->replace('_', ' ')->title()->toString(),
        };
    }
}
