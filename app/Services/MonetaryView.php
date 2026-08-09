<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\User;
use App\Support\BusinessTypes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MonetaryView
{
    /** General secondary factor: full amounts (no discount). Only labor is reduced. */
    public const FACTOR = 1.0;

    /** Labor-only secondary factor: show 50% of real labor. */
    public const LABOR_FACTOR = 0.5;

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

    /**
     * Line-item display factor: labor at 50%, everything else at 75%.
     */
    public function lineFactor(?string $type): float
    {
        return $type === 'labor' ? self::LABOR_FACTOR : self::FACTOR;
    }

    /**
     * Blended payment/advance factor for a bill so receipts track scaled charges.
     *
     * @param  iterable<int, Model|array<string, mixed>>  $items
     */
    public function paymentFactorForItems(iterable $items): float
    {
        $originalNet = 0.0;
        $scaledNet = 0.0;

        foreach ($items as $item) {
            $type = is_array($item) ? (string) ($item['type'] ?? '') : (string) ($item->type ?? '');
            $line = (float) (is_array($item) ? ($item['line_total'] ?? 0) : ($item->line_total ?? 0));

            if (in_array($type, BusinessTypes::discountItemTypes(), true)) {
                $originalNet -= $line;
                $scaledNet -= $line * self::FACTOR;
            } elseif (in_array($type, BusinessTypes::chargeItemTypes(), true)) {
                $factor = $this->lineFactor($type);
                $originalNet += $line;
                $scaledNet += $line * $factor;
            }
        }

        $originalNet = max(0.0, round($originalNet, 2));
        $scaledNet = max(0.0, round($scaledNet, 2));

        return $originalNet > 0 ? ($scaledNet / $originalNet) : self::FACTOR;
    }

    /**
     * Scale a payment or advance using the parent bill's blended charge factor.
     *
     * @param  iterable<int, Model|array<string, mixed>>  $items
     */
    public function scaleReceipt(float $amount, iterable $items): float
    {
        if (! $this->active()) {
            return round($amount, 2);
        }

        return round($amount * $this->paymentFactorForItems($items), 2);
    }

    /**
     * Scale a non-receipt money value (expenses, payroll, catalog, etc.).
     */
    public function scaleExpense(float $amount): float
    {
        if (! $this->active()) {
            return round($amount, 2);
        }

        return round($amount * self::FACTOR, 2);
    }

    public function amount(mixed $value, ?float $factor = null): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        $scaled = round((float) $value * ($factor ?? self::FACTOR), 2);

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
            if ($payload instanceof Bill) {
                $payload->loadMissing(['items', 'payments']);
            }

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
        $isBill = isset($data['items']) && is_array($data['items']) && array_key_exists('subtotal', $data);
        $originalBill = $isBill ? $this->summarizeBillMoney($data) : null;
        $isLaborItem = ($data['type'] ?? null) === 'labor';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($key === 'expense_breakdown') {
                    $data[$key] = collect($value)->map(fn ($amount) => $this->amount($amount))->all();

                    continue;
                }

                if (array_is_list($value)) {
                    $data[$key] = array_map(
                        fn ($row) => is_array($row) ? $this->transformArray($row) : $row,
                        $value
                    );

                    continue;
                }

                $data[$key] = $this->transformArray($value);

                continue;
            }

            if (in_array((string) $key, self::MONEY_KEYS, true)) {
                $factor = ($isLaborItem && in_array((string) $key, ['unit_price', 'line_total'], true))
                    ? self::LABOR_FACTOR
                    : self::FACTOR;
                $data[$key] = $this->amount($value, $factor);
            }
        }

        if ($originalBill !== null) {
            $data = $this->recalculateBillTotals($data, $originalBill);
        }

        return $data;
    }

    /**
     * Snapshot unscaled bill money so payments can track the blended charge factor.
     *
     * @param  array<string, mixed>  $bill
     * @return array{net: float, item_lines: list<float>, payment_amounts: list<float>}
     */
    private function summarizeBillMoney(array $bill): array
    {
        $charges = 0.0;
        $discounts = 0.0;
        $itemLines = [];

        foreach ($bill['items'] ?? [] as $item) {
            if (! is_array($item)) {
                $itemLines[] = 0.0;

                continue;
            }

            $line = (float) ($item['line_total'] ?? 0);
            $itemLines[] = $line;
            $type = (string) ($item['type'] ?? '');

            if (in_array($type, BusinessTypes::discountItemTypes(), true)) {
                $discounts += $line;
            } elseif (in_array($type, BusinessTypes::chargeItemTypes(), true)) {
                $charges += $line;
            }
        }

        $paymentAmounts = [];
        foreach ($bill['payments'] ?? [] as $payment) {
            $paymentAmounts[] = is_array($payment) ? (float) ($payment['amount'] ?? 0) : 0.0;
        }

        return [
            'net' => max(0.0, round($charges - $discounts, 2)),
            'item_lines' => $itemLines,
            'payment_amounts' => $paymentAmounts,
        ];
    }

    /**
     * Keep bill headers and payments consistent when labor uses 50% and other lines stay at 100%.
     * Payments/advances are scaled by scaledNet/originalNet so paid/due/balance stay aligned.
     *
     * @param  array<string, mixed>  $bill
     * @param  array{net: float, item_lines: list<float>, payment_amounts: list<float>}  $original
     * @return array<string, mixed>
     */
    private function recalculateBillTotals(array $bill, array $original): array
    {
        $charges = 0.0;
        $discounts = 0.0;

        foreach ($bill['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            $line = (float) ($item['line_total'] ?? 0);

            if (in_array($type, BusinessTypes::discountItemTypes(), true)) {
                $discounts += $line;
            } elseif (in_array($type, BusinessTypes::chargeItemTypes(), true)) {
                $charges += $line;
            }
        }

        $netBill = max(0.0, round($charges - $discounts, 2));
        $payFactor = $original['net'] > 0
            ? ($netBill / $original['net'])
            : self::FACTOR;

        $advances = 0.0;
        foreach ($bill['items'] as $index => $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'advance') {
                continue;
            }

            $originalLine = (float) ($original['item_lines'][$index] ?? 0);
            $scaledLine = round($originalLine * $payFactor, 2);
            $asItemString = is_string($item['line_total'] ?? null);
            $bill['items'][$index]['line_total'] = $this->formatMoney($scaledLine, $asItemString);
            if (array_key_exists('unit_price', $item)) {
                $bill['items'][$index]['unit_price'] = $this->formatMoney(
                    $scaledLine,
                    is_string($item['unit_price'] ?? null)
                );
            }
            $advances += $scaledLine;
        }

        $payments = 0.0;
        foreach ($bill['payments'] ?? [] as $index => $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $originalAmount = (float) ($original['payment_amounts'][$index] ?? 0);
            $scaledAmount = round($originalAmount * $payFactor, 2);
            $bill['payments'][$index]['amount'] = $this->formatMoney(
                $scaledAmount,
                is_string($payment['amount'] ?? null)
            );
            $payments += $scaledAmount;
        }

        $amountPaid = round($advances + $payments, 2);
        $balanceDue = max(0.0, round($netBill - $amountPaid, 2));
        $customerBalance = max(0.0, round($amountPaid - $netBill, 2));

        $asString = is_string($bill['subtotal'] ?? null);

        $bill['subtotal'] = $this->formatMoney($charges, $asString);
        $bill['total_deductions'] = $this->formatMoney(round($discounts + $advances, 2), $asString);
        $bill['amount_paid'] = $this->formatMoney($amountPaid, $asString);
        $bill['balance_due'] = $this->formatMoney($balanceDue, $asString);
        $bill['customer_balance'] = $this->formatMoney($customerBalance, $asString);

        return $bill;
    }

    private function formatMoney(float $value, bool $asString): float|string
    {
        return $asString ? number_format($value, 2, '.', '') : $value;
    }
}
