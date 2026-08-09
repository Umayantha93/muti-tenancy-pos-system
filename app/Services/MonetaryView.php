<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MonetaryView
{
    public const FACTOR = 0.65;

    /** @var list<string> */
    public const MONEY_KEYS = [
        'subtotal',
        'total_deductions',
        'amount_paid',
        'balance_due',
        'customer_balance',
        'unit_price',
        'line_total',
        'amount',
        'price',
        'cost_price',
        'base_salary',
        'overtime_pay',
        'bonus',
        'deductions',
        'net_salary',
        'overtime_hourly_rate',
        'nightly_rate',
        'income',
        'expenses',
        'net_profit',
        'today_income',
        'monthly_income',
        'monthly_expenses',
        'monthly_profit',
        'total',
        'debit',
        'credit',
        'balance',
    ];

    public static function for(?User $user = null): self
    {
        return new self($user ?? auth()->user());
    }

    public function __construct(private readonly ?User $user)
    {
    }

    public function active(): bool
    {
        if (! $this->user || ! $this->user->is_secondary_view) {
            return false;
        }

        $tenant = $this->user->relationLoaded('tenant')
            ? $this->user->tenant
            : $this->user->tenant()->first();

        return (bool) ($tenant?->dual_financial_view_enabled);
    }

    public function factor(): float
    {
        return self::FACTOR;
    }

    public function amount(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        $scaled = round((float) $value * self::FACTOR, 2);

        return is_string($value) ? number_format($scaled, 2, '.', '') : $scaled;
    }

    public function transform(mixed $payload): mixed
    {
        if (! $this->active()) {
            return $payload;
        }

        if ($payload instanceof LengthAwarePaginator) {
            $payload->setCollection(
                $payload->getCollection()->map(fn ($item) => $this->transform($item))
            );

            return $payload;
        }

        if ($payload instanceof Model) {
            return $this->transformArray($payload->toArray());
        }

        if ($payload instanceof Collection) {
            return $payload->map(fn ($item) => $this->transform($item))->all();
        }

        if (is_array($payload)) {
            return $this->transformArray($payload);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function transformArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Expense breakdown: category => amount
                if ($key === 'expense_breakdown') {
                    $data[$key] = collect($value)->map(fn ($amount) => $this->amount($amount))->all();

                    continue;
                }

                $data[$key] = $this->transformArray($value);

                continue;
            }

            if (in_array((string) $key, self::MONEY_KEYS, true)) {
                $data[$key] = $this->amount($value);
            }
        }

        return $data;
    }
}
