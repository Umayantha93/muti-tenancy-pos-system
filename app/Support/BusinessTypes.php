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

    public const MOBILE_SHOP = 'mobile_shop';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::GARAGE, self::TYRE, self::DEVICE_REPAIR, self::PAINT,
            self::PHOTOGRAPHY, self::CLOTHING, self::SALON, self::COTTAGE,
            self::STORE, self::MOBILE_SHOP,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function featureMatrix(): array
    {
        $shared = ['customers', 'billing', 'bill_sms', 'bill_whatsapp', 'bill_profits', 'employees_management', 'attendance', 'payroll', 'balance_sheet', 'cash_up', 'reports'];
        $inventoryExtras = ['purchase_orders', 'part_fitment'];

        $garageFamily = array_merge(['admit_vehicle', 'parts_inventory', 'suppliers', 'warranties', 'job_bookings', ...$inventoryExtras], $shared);
        $retailFamily = array_merge(['retail_pos', 'product_catalog', 'suppliers'], $shared);
        $storeFamily = array_merge(['parts_inventory', 'suppliers', 'repair_bills', 'warranties', ...$inventoryExtras, 'serial_inventory'], $shared);

        return [
            self::GARAGE => array_merge($garageFamily, [
                'admit_repair', 'admit_service', 'job_board', 'owner_bill_sms', 'job_photos', 'job_videos',
                'service_reminders', 'service_ops_report', 'station_consumables',
            ]),
            self::TYRE => $garageFamily,
            self::DEVICE_REPAIR => array_merge($garageFamily, ['serial_inventory']),
            self::PAINT => $garageFamily,
            self::PHOTOGRAPHY => array_merge(['photo_bookings', 'photo_packages'], $shared),
            self::CLOTHING => $retailFamily,
            self::SALON => array_merge(['photo_bookings', 'photo_packages', 'retail_pos', 'product_catalog'], $shared),
            self::COTTAGE => array_merge(['cottage_rooms', 'cottage_stays'], $shared),
            self::STORE => $storeFamily,
            self::MOBILE_SHOP => $storeFamily,
        ];
    }

    /**
     * Optional modules the super-admin can enable later. Not ticked on onboard.
     *
     * @return list<string>
     */
    public static function optionalFeatures(string $type): array
    {
        $inventoryExtras = ['purchase_orders', 'part_fitment'];
        $bay = ['job_bookings'];
        $whatsapp = ['bill_whatsapp'];

        return match ($type) {
            self::STORE => ['repair_bills', 'warranties', ...$inventoryExtras, 'serial_inventory', ...$whatsapp],
            self::MOBILE_SHOP => [...$inventoryExtras, 'serial_inventory', ...$whatsapp],
            self::GARAGE => [
                'owner_bill_sms', 'service_ops_report', 'job_photos', 'job_videos',
                'job_board', 'job_bookings', 'service_reminders', 'station_consumables',
                ...$inventoryExtras, 'cash_up', ...$whatsapp,
            ],
            self::TYRE, self::PAINT => [...$bay, ...$inventoryExtras, 'cash_up', ...$whatsapp],
            self::DEVICE_REPAIR => [...$bay, ...$inventoryExtras, 'serial_inventory', 'cash_up', ...$whatsapp],
            default => ['cash_up', ...$whatsapp],
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
            self::CLOTHING, self::STORE, self::MOBILE_SHOP => 'SALE',
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
            'communications', 'phone_shop' => self::MOBILE_SHOP,
            'parts_shop' => self::STORE,
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
            'mobile-pro',
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
            self::MOBILE_SHOP => 'mobile-pro',
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
            self::STORE, self::MOBILE_SHOP => [
                ['value' => 'part', 'label' => 'Item', 'kind' => 'stock', 'allow_qty' => true],
                ['value' => 'charge', 'label' => 'Quick job', 'kind' => 'charge', 'allow_qty' => true],
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
        return in_array($type, [self::STORE, self::MOBILE_SHOP], true);
    }

    public static function usesCounterHome(string $type): bool
    {
        return self::usesStoreCounter($type);
    }

    public static function usesDeviceJobs(string $type): bool
    {
        return $type === self::DEVICE_REPAIR;
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

    /**
     * Nested ticks on the super-admin / staff feature plan. Child => parent.
     *
     * @return array<string, string>
     */
    public static function nestedUnder(): array
    {
        return [
            'admit_repair' => 'admit_vehicle',
            'admit_service' => 'admit_vehicle',
            'job_board' => 'admit_vehicle',
            'owner_bill_sms' => 'admit_vehicle',
            'job_photos' => 'admit_vehicle',
            'job_videos' => 'admit_vehicle',
            'service_reminders' => 'bill_sms',
            'serial_inventory' => 'parts_inventory',
            'bill_whatsapp' => 'billing',
        ];
    }

    public static function parentKey(string $key): ?string
    {
        return self::nestedUnder()[$key] ?? null;
    }

    /**
     * Extra keys that must also be on for this module.
     *
     * @return list<string>
     */
    public static function requires(string $key): array
    {
        return match ($key) {
            'admit_repair', 'admit_service', 'job_board', 'owner_bill_sms', 'job_photos', 'job_videos' => ['admit_vehicle'],
            'service_reminders' => ['bill_sms', 'admit_service'],
            'service_ops_report' => ['admit_service'],
            'serial_inventory' => ['parts_inventory'],
            'station_consumables' => ['parts_inventory'],
            'bill_whatsapp' => ['billing'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $enabled
     * @return list<string>
     */
    public static function fillGarageAdmitDefaults(string $type, array $enabled): array
    {
        if ($type !== self::GARAGE || ! in_array('admit_vehicle', $enabled, true)) {
            return $enabled;
        }
        if (! in_array('admit_repair', $enabled, true) && ! in_array('admit_service', $enabled, true)) {
            $enabled[] = 'admit_repair';
            $enabled[] = 'admit_service';
        }

        return array_values(array_unique($enabled));
    }

    /**
     * Drop children when a parent is off. Reject garage admit with neither kind.
     *
     * @param  list<string>  $enabled
     * @return list<string>
     */
    public static function normalizePlan(string $type, array $enabled, bool $rejectEmptyAdmit = true): array
    {
        $allowed = self::featuresForType($type);
        $set = array_flip(array_values(array_intersect($enabled, $allowed)));

        if (! isset($set['admit_vehicle'])) {
            unset($set['admit_repair'], $set['admit_service'], $set['job_board'], $set['owner_bill_sms'], $set['job_photos'], $set['job_videos']);
        }
        if (! isset($set['bill_sms'])) {
            unset($set['service_reminders']);
        }
        if (! isset($set['admit_service'])) {
            unset($set['service_reminders'], $set['service_ops_report']);
        }
        if (! isset($set['parts_inventory']) && ! isset($set['product_catalog'])) {
            unset($set['serial_inventory']);
        }
        if (! isset($set['parts_inventory'])) {
            unset($set['station_consumables']);
        }
        if (! isset($set['billing'])) {
            unset($set['bill_whatsapp']);
        }

        if ($rejectEmptyAdmit && $type === self::GARAGE && isset($set['admit_vehicle'])
            && ! isset($set['admit_repair']) && ! isset($set['admit_service'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'features' => ['Choose Repair, Service, or both.'],
            ]);
        }

        return array_keys($set);
    }

    /**
     * @return list<string>
     */
    public static function allowedJobKinds(\App\Models\User $user): array
    {
        $type = self::normalizeLegacy((string) ($user->tenant?->business_type ?? self::GARAGE));
        if ($type !== self::GARAGE) {
            return [\App\Models\Bill::JOB_KIND_REPAIR, \App\Models\Bill::JOB_KIND_SERVICE];
        }

        $attached = $user->tenant?->features()->whereIn('features.key', ['admit_repair', 'admit_service'])->pluck('features.key') ?? collect();
        if ($attached->isEmpty()) {
            return $user->canAccessFeature('admit_vehicle')
                ? [\App\Models\Bill::JOB_KIND_REPAIR, \App\Models\Bill::JOB_KIND_SERVICE]
                : [];
        }

        $kinds = [];
        if ($user->canAccessFeature('admit_repair')) {
            $kinds[] = \App\Models\Bill::JOB_KIND_REPAIR;
        }
        if ($user->canAccessFeature('admit_service')) {
            $kinds[] = \App\Models\Bill::JOB_KIND_SERVICE;
        }

        return $kinds;
    }

    public static function jobKindAllowed(\App\Models\User $user, ?string $kind): bool
    {
        if ($kind === null || $kind === \App\Models\Bill::JOB_KIND_PARTS_SALE) {
            return true;
        }
        if (! in_array($kind, [\App\Models\Bill::JOB_KIND_REPAIR, \App\Models\Bill::JOB_KIND_SERVICE], true)) {
            return true;
        }

        $type = self::normalizeLegacy((string) ($user->tenant?->business_type ?? self::GARAGE));
        if ($type !== self::GARAGE) {
            return true;
        }

        return in_array($kind, self::allowedJobKinds($user), true);
    }

    public static function defaultJobKind(\App\Models\User $user, string $type): string
    {
        if (self::usesStoreCounter($type)) {
            return \App\Models\Bill::JOB_KIND_PARTS_SALE;
        }
        $allowed = self::allowedJobKinds($user);
        if (count($allowed) === 1) {
            return $allowed[0];
        }

        return \App\Models\Bill::JOB_KIND_REPAIR;
    }
}
