<?php

namespace App\Http\Controllers;

use App\Models\BillPayment;
use App\Models\BillRefund;
use App\Models\Branch;
use App\Models\CashUp;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BranchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashUpController extends Controller
{
    /** @var list<int> */
    public const NOTE_VALUES = [5000, 2000, 1000, 500, 100, 50, 20];

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $cashierId = $this->cashierId($request, $data['user_id'] ?? null);
        $shopId = $this->shopId($request);
        $row = $this->findRow($cashierId, $date, $shopId);
        $float = (float) ($row?->opening_float ?? 0);
        $base = $this->expectedFor($cashierId, $date, $shopId);
        $bankPayments = $this->bankPayments($cashierId, $date, $shopId);
        $lines = collect($row?->bank_lines ?? []);

        return response()->json([
            'date' => $date,
            'user_id' => $cashierId,
            'cash_takings' => $base['expected_cash'],
            'opening_float' => $float,
            'cash_count' => $row?->cash_count,
            'expected' => $this->withFloat($base, $float),
            'bank_payments' => $this->presentBankPayments($bankPayments, $lines),
            'bank_extras' => $this->presentExtras($lines),
            'cash_up' => $row,
            'difference' => $row ? [
                'cash' => round((float) $row->counted_cash - (float) $row->expected_cash, 2),
                'card' => round((float) $row->counted_card - (float) $row->expected_card, 2),
                'bank' => round((float) $row->counted_bank - (float) $row->expected_bank, 2),
                'cheque' => round((float) $row->counted_cheque - (float) $row->expected_cheque, 2),
            ] : null,
            'cashiers' => $this->cashiers($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'opening_float' => ['nullable', 'numeric', 'min:0'],
            'cash_count' => ['required', 'array'],
            'cash_count.5000' => ['nullable', 'integer', 'min:0'],
            'cash_count.2000' => ['nullable', 'integer', 'min:0'],
            'cash_count.1000' => ['nullable', 'integer', 'min:0'],
            'cash_count.500' => ['nullable', 'integer', 'min:0'],
            'cash_count.100' => ['nullable', 'integer', 'min:0'],
            'cash_count.50' => ['nullable', 'integer', 'min:0'],
            'cash_count.20' => ['nullable', 'integer', 'min:0'],
            'cash_count.coins' => ['nullable', 'numeric', 'min:0'],
            'bank_lines' => ['nullable', 'array'],
            'bank_lines.*.payment_id' => ['nullable', 'integer'],
            'bank_lines.*.seen' => ['required', 'boolean'],
            'bank_lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'bank_lines.*.reference' => ['nullable', 'string', 'max:255'],
            'counted_card' => ['required', 'numeric', 'min:0'],
            'counted_cheque' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $cashierId = $this->cashierId($request, $data['user_id'] ?? null);
        $shopId = $this->shopId($request);
        $float = round((float) ($data['opening_float'] ?? 0), 2);
        $expected = $this->withFloat($this->expectedFor($cashierId, $date, $shopId), $float);
        $bankPayments = $this->bankPayments($cashierId, $date, $shopId);
        $bankLines = $this->normalizeBankLines($data['bank_lines'] ?? [], $bankPayments);

        CashUp::query()->updateOrCreate(
            [
                'user_id' => $cashierId,
                'business_date' => $date,
                'branch_id' => $shopId,
            ],
            [
                ...$expected,
                'opening_float' => $float,
                'cash_count' => $this->normalizeCashCount($data['cash_count']),
                'bank_lines' => $bankLines,
                'counted_cash' => $this->countedCash($data['cash_count']),
                'counted_card' => $data['counted_card'],
                'counted_bank' => $this->countedBank($bankLines, $bankPayments),
                'counted_cheque' => $data['counted_cheque'],
                'notes' => $data['notes'] ?? null,
                'closed_at' => now(),
            ],
        );

        return $this->show($request->merge(['date' => $date, 'user_id' => $cashierId]));
    }

    /**
     * @return array{expected_cash: float, expected_card: float, expected_bank: float, expected_cheque: float}
     */
    private function expectedFor(int $cashierId, string $date, ?int $shopId): array
    {
        $buckets = ['expected_cash' => 0.0, 'expected_card' => 0.0, 'expected_bank' => 0.0, 'expected_cheque' => 0.0];

        $payments = BillPayment::query()
            ->where('received_by', $cashierId)
            ->whereDate('paid_at', $date)
            ->whereHas('bill', function ($query) use ($shopId) {
                if ($shopId) {
                    $query->where('branch_id', $shopId);
                } else {
                    BranchQuery::constrain($query);
                }
            })
            ->get(['amount', 'method']);

        foreach ($payments as $payment) {
            $buckets[$this->bucket($payment->method)] += (float) $payment->amount;
        }

        $refunds = BillRefund::query()
            ->where('created_by', $cashierId)
            ->whereDate('refunded_at', $date)
            ->when($shopId, fn ($query) => $query->where('branch_id', $shopId), fn ($query) => BranchQuery::constrain($query))
            ->get(['amount', 'method']);

        foreach ($refunds as $refund) {
            $buckets[$this->bucket($refund->method)] -= (float) $refund->amount;
        }

        return collect($buckets)
            ->map(fn ($value) => round((float) $value, 2))
            ->all();
    }

    /**
     * @param  array{expected_cash: float, expected_card: float, expected_bank: float, expected_cheque: float}  $expected
     * @return array{expected_cash: float, expected_card: float, expected_bank: float, expected_cheque: float}
     */
    private function withFloat(array $expected, float $float): array
    {
        $expected['expected_cash'] = round($expected['expected_cash'] + $float, 2);

        return $expected;
    }

    /**
     * @return Collection<int, BillPayment>
     */
    private function bankPayments(int $cashierId, string $date, ?int $shopId): Collection
    {
        return BillPayment::query()
            ->with(['bill:id,bill_number,customer_id', 'bill.customer:id,name'])
            ->where('received_by', $cashierId)
            ->whereDate('paid_at', $date)
            ->whereNotIn('method', ['cash', 'card', 'cheque'])
            ->whereHas('bill', function ($query) use ($shopId) {
                if ($shopId) {
                    $query->where('branch_id', $shopId);
                } else {
                    BranchQuery::constrain($query);
                }
            })
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, BillPayment>  $payments
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function presentBankPayments(Collection $payments, Collection $lines): array
    {
        $seen = $lines
            ->filter(fn ($line) => ! empty($line['payment_id']))
            ->keyBy(fn ($line) => (int) $line['payment_id']);

        return $payments->map(function (BillPayment $payment) use ($seen) {
            $line = $seen->get($payment->id);

            return [
                'id' => $payment->id,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'bill_number' => $payment->bill?->bill_number,
                'customer_name' => $payment->bill?->customer?->name,
                'seen' => is_array($line) && (bool) ($line['seen'] ?? false),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function presentExtras(Collection $lines): array
    {
        return $lines
            ->filter(fn ($line) => empty($line['payment_id']))
            ->map(fn ($line) => [
                'amount' => $line['amount'] ?? 0,
                'reference' => $line['reference'] ?? null,
                'seen' => (bool) ($line['seen'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $count
     * @return array<string, int|float>
     */
    private function normalizeCashCount(array $count): array
    {
        $normalized = [];
        foreach (self::NOTE_VALUES as $value) {
            $normalized[(string) $value] = (int) ($count[(string) $value] ?? 0);
        }
        $normalized['coins'] = round((float) ($count['coins'] ?? 0), 2);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $count
     */
    private function countedCash(array $count): float
    {
        $total = (float) ($count['coins'] ?? 0);
        foreach (self::NOTE_VALUES as $value) {
            $total += $value * (int) ($count[(string) $value] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, BillPayment>  $payments
     * @return list<array<string, mixed>>
     */
    private function normalizeBankLines(array $lines, Collection $payments): array
    {
        $known = $payments->keyBy('id');
        $normalized = [];

        foreach ($lines as $line) {
            $seen = (bool) ($line['seen'] ?? false);
            if (! empty($line['payment_id'])) {
                $id = (int) $line['payment_id'];
                if (! $known->has($id)) {
                    throw ValidationException::withMessages([
                        'bank_lines' => ['That transfer is not on this drawer.'],
                    ]);
                }
                $normalized[] = ['payment_id' => $id, 'seen' => $seen];
                continue;
            }
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($seen && $amount <= 0) {
                throw ValidationException::withMessages([
                    'bank_lines' => ['Enter the amount of a transfer that is not on a bill.'],
                ]);
            }
            if ($amount <= 0) {
                continue;
            }
            $normalized[] = [
                'amount' => $amount,
                'reference' => $line['reference'] ?? null,
                'seen' => $seen,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, BillPayment>  $payments
     */
    private function countedBank(array $lines, Collection $payments): float
    {
        $known = $payments->keyBy('id');
        $total = 0.0;
        foreach ($lines as $line) {
            if (! ($line['seen'] ?? false)) {
                continue;
            }
            if (! empty($line['payment_id'])) {
                $total += (float) $known->get((int) $line['payment_id'])?->amount;
                continue;
            }
            $total += (float) ($line['amount'] ?? 0);
        }

        return round($total, 2);
    }

    private function findRow(int $cashierId, string $date, ?int $shopId): ?CashUp
    {
        return CashUp::query()
            ->with('cashier:id,name')
            ->when($shopId, fn ($query) => $query->where('branch_id', $shopId))
            ->where('user_id', $cashierId)
            ->whereDate('business_date', $date)
            ->first();
    }

    private function bucket(string $method): string
    {
        return match ($method) {
            'cash' => 'expected_cash',
            'card' => 'expected_card',
            'cheque' => 'expected_cheque',
            default => 'expected_bank',
        };
    }

    private function cashierId(Request $request, ?int $requested): int
    {
        if ($request->user()->role === 'staff') {
            return (int) $request->user()->id;
        }

        return $requested ?: (int) $request->user()->id;
    }

    private function shopId(Request $request): ?int
    {
        return BranchQuery::idForRead($request)
            ?? BranchContext::id()
            ?? Branch::defaultIdFor((int) $request->user()->tenant_id);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function cashiers(Request $request): array
    {
        if ($request->user()->role === 'staff') {
            return [['id' => (int) $request->user()->id, 'name' => (string) $request->user()->name]];
        }

        return User::query()
            ->whereIn('role', ['business_owner', 'staff'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }
}
