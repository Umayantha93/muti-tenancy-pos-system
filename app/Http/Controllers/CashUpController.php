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
use Illuminate\Validation\Rule;

class CashUpController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $cashierId = $this->cashierId($request, $data['user_id'] ?? null);
        $shopId = $this->shopId($request);
        $expected = $this->expectedFor($cashierId, $date, $shopId);
        $row = CashUp::query()
            ->with('cashier:id,name')
            ->when($shopId, fn ($query) => $query->where('branch_id', $shopId))
            ->where('user_id', $cashierId)
            ->whereDate('business_date', $date)
            ->first();

        return response()->json([
            'date' => $date,
            'user_id' => $cashierId,
            'expected' => $expected,
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
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'counted_card' => ['required', 'numeric', 'min:0'],
            'counted_bank' => ['required', 'numeric', 'min:0'],
            'counted_cheque' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        $cashierId = $this->cashierId($request, $data['user_id'] ?? null);
        $shopId = $this->shopId($request);
        $expected = $this->expectedFor($cashierId, $date, $shopId);

        $row = CashUp::query()->updateOrCreate(
            [
                'user_id' => $cashierId,
                'business_date' => $date,
                'branch_id' => $shopId,
            ],
            [
                ...$expected,
                'counted_cash' => $data['counted_cash'],
                'counted_card' => $data['counted_card'],
                'counted_bank' => $data['counted_bank'],
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
