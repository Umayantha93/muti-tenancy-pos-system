<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'name',
    'mode',
    'value',
    'sort_order',
    'active',
])]
class DiscountType extends Model
{
    use BelongsToTenant;

    public const MODE_AMOUNT = 'amount';

    public const MODE_PERCENT = 'percent';

    /**
     * @return list<array{name: string, mode: string, value: float, sort_order: int}>
     */
    public static function defaultCatalog(): array
    {
        return [
            ['name' => 'Staff discount', 'mode' => self::MODE_PERCENT, 'value' => 10, 'sort_order' => 10],
            ['name' => 'Loyalty', 'mode' => self::MODE_PERCENT, 'value' => 5, 'sort_order' => 20],
            ['name' => 'Walk-in', 'mode' => self::MODE_AMOUNT, 'value' => 500, 'sort_order' => 30],
            ['name' => 'Promo', 'mode' => self::MODE_AMOUNT, 'value' => 1000, 'sort_order' => 40],
        ];
    }

    public static function seedDefaultsFor(int $tenantId, ?string $businessType = null): void
    {
        if (! in_array($businessType, [BusinessTypes::GARAGE, BusinessTypes::PAINT], true)) {
            return;
        }
        if (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        foreach (static::defaultCatalog() as $row) {
            $type = new static;
            $type->forceFill([
                'tenant_id' => $tenantId,
                'name' => $row['name'],
                'mode' => $row['mode'],
                'value' => $row['value'],
                'sort_order' => $row['sort_order'],
                'active' => true,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyToItemPayload(array $data, int $tenantId, float $chargeSubtotal = 0): array
    {
        if (empty($data['discount_type_id'])) {
            return $data;
        }

        $type = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($data['discount_type_id']);

        if (! $type || ! $type->active) {
            throw ValidationException::withMessages([
                'discount_type_id' => ['This discount type is not available.'],
            ]);
        }

        $data['type'] = 'discount';
        $data['description'] = $type->name;
        $data['quantity'] = 1;
        $data['discount_type_id'] = $type->id;

        if ($type->mode === self::MODE_PERCENT) {
            $percent = max(0, min(100, (float) $type->value));
            $data['unit_price'] = round(max(0, $chargeSubtotal) * ($percent / 100), 2);
            $data['description'] = $type->name.' ('.$percent.'%)';
        } else {
            $data['unit_price'] = round(max(0, (float) $type->value), 2);
        }

        return $data;
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
