<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'bill_id',
    'part_id',
    'service_addon_id',
    'labor_item_id',
    'panel_group_id',
    'panel_name',
    'type',
    'section',
    'employee_id',
    'description',
    'included_services',
    'quantity',
    'unit_price',
    'discount_percent',
    'line_discount',
    'purchase_unit_cost',
    'purchase_expense_id',
    'line_total',
    'is_free',
    'commission_amount',
    'warranty_months',
    'warranty_starts_on',
    'warranty_until',
])]
class BillItem extends Model
{
    use BelongsToTenant;

    protected $hidden = ['employee'];

    protected function casts(): array
    {
        return [
            'included_services' => 'array',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'line_discount' => 'decimal:2',
            'purchase_unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'is_free' => 'boolean',
            'commission_amount' => 'decimal:2',
            'warranty_months' => 'integer',
            'warranty_starts_on' => 'date:Y-m-d',
            'warranty_until' => 'date:Y-m-d',
        ];
    }

    public const COMMISSION_TYPES = ['labor', 'service_addon', 'service', 'charge'];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function earnsCommission(): bool
    {
        return in_array($this->type, self::COMMISSION_TYPES, true);
    }

    public function sellsBelowCost(): bool
    {
        if ($this->purchase_unit_cost === null || in_array($this->type, ['discount', 'customer_part', 'advance'], true)) {
            return false;
        }

        $cost = round((float) $this->purchase_unit_cost * (float) $this->quantity, 2);

        return (float) $this->line_total + 0.009 < $cost;
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        $user = auth()->user();
        if ($user && $user->canAccessFeature('show_bill_technicians') && $this->earnsCommission()) {
            $name = $this->relationLoaded('employee') ? $this->employee?->name : null;
            if (is_string($name) && $name !== '') {
                $array['technician_name'] = $name;
            }
        }
        if ($user && $user->canAccessFeature('line_discounts')) {
            $array['below_cost'] = $this->sellsBelowCost();
        }

        return $array;
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(PartSerial::class);
    }

    public function laborItem(): BelongsTo
    {
        return $this->belongsTo(LaborItem::class);
    }

    public function serviceAddon(): BelongsTo
    {
        return $this->belongsTo(ServiceAddon::class);
    }

    public function purchaseExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'purchase_expense_id');
    }
}
