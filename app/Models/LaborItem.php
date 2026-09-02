<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['labor_category_id', 'name', 'hourly_rate', 'standard_hours', 'sort_order', 'active'])]
class LaborItem extends Model
{
    use BelongsToTenant;

    protected $appends = ['standard_price'];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'standard_hours' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function getStandardPriceAttribute(): string
    {
        return number_format((float) $this->hourly_rate * (float) $this->standard_hours, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyToItemPayload(array $data, int $tenantId): array
    {
        if (empty($data['labor_item_id'])) {
            return $data;
        }

        $item = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($data['labor_item_id']);

        if (! $item || ! $item->active) {
            throw ValidationException::withMessages([
                'labor_item_id' => ['This labor item is not available.'],
            ]);
        }

        $data['type'] = 'labor';
        $data['description'] = $item->name;
        $data['unit_price'] = (float) $item->hourly_rate;
        if (! isset($data['quantity']) || $data['quantity'] === null || $data['quantity'] === '') {
            $data['quantity'] = (float) $item->standard_hours;
        }
        $data['labor_item_id'] = $item->id;
        $data['purchase_unit_cost'] = null;

        return $data;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LaborCategory::class, 'labor_category_id');
    }

    public function billItems(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }
}
