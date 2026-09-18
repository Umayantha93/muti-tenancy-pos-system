<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->withCount(['vehicles', 'bills'])
            ->withSum(['bills as outstanding_balance' => fn ($query) => $query->where('balance_due', '>', 0)], 'balance_due')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('phone'), fn ($query) => $query->where('phone', 'like', '%'.$request->string('phone').'%'))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $lastBills = Bill::query()
            ->whereIn('customer_id', $customers->getCollection()->pluck('id'))
            ->orderByDesc('admission_date')
            ->orderByDesc('id')
            ->get(['id', 'customer_id', 'bill_number', 'admission_date', 'status', 'balance_due', 'subtotal'])
            ->unique('customer_id')
            ->keyBy('customer_id');

        $customers->getCollection()->transform(function (Customer $customer) use ($lastBills) {
            $customer->setAttribute('outstanding_balance', round((float) ($customer->outstanding_balance ?? 0), 2));
            $customer->setAttribute('last_bill', $lastBills->get($customer->id));
            $customer->setAttribute('sms_opt_in', (bool) $customer->sms_opt_in);
            $this->appendAgeing($customer);

            return $customer;
        });

        return $this->moneyJson($customers);
    }

    public function outstanding(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessFeature('billing'), 403, 'This feature is not available for this account.');

        $customers = Customer::query()
            ->withCount(['vehicles', 'bills'])
            ->withSum(['bills as outstanding_balance' => fn ($query) => $query->where('balance_due', '>', 0)], 'balance_due')
            ->whereHas('bills', fn ($query) => $query->where('balance_due', '>', 0))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('outstanding_balance')
            ->paginate($request->integer('per_page', 50));

        $customers->getCollection()->transform(fn (Customer $customer) => $this->decorate($customer));

        return $this->moneyJson($customers);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->decorate(Customer::create($this->validated($request))), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load([
            'vehicles',
            'bills' => fn ($query) => $query->with('vehicle')->latest('admission_date')->orderByDesc('id'),
        ])->loadCount(['vehicles', 'bills']);

        return $this->moneyJson($this->decorate($customer));
    }

    public function statement(Customer $customer): JsonResponse
    {
        $customer->load([
            'bills' => fn ($query) => $query->with('vehicle')->latest('admission_date')->orderByDesc('id'),
        ])->loadCount(['vehicles', 'bills']);

        return $this->moneyJson($this->decorate($customer));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->validated($request, true));

        return response()->json($this->decorate($customer->refresh()));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(null, 204);
    }

    private function decorate(Customer $customer): Customer
    {
        $outstanding = (float) $customer->bills()->where('balance_due', '>', 0)->sum('balance_due');
        $lastBill = $customer->relationLoaded('bills')
            ? $customer->bills->sortByDesc(fn (Bill $bill) => sprintf('%s-%010d', $bill->admission_date, $bill->id))->first()
            : $customer->bills()->orderByDesc('admission_date')->orderByDesc('id')->first();

        $customer->setAttribute('outstanding_balance', round($outstanding, 2));
        $customer->setAttribute('last_bill', $lastBill);
        $customer->setAttribute('sms_opt_in', (bool) $customer->sms_opt_in);
        $this->appendAgeing($customer);

        return $customer;
    }

    private function appendAgeing(Customer $customer): void
    {
        $oldest = $customer->bills()
            ->where('balance_due', '>', 0)
            ->orderBy('admission_date')
            ->orderBy('id')
            ->first();

        if (! $oldest) {
            $customer->setAttribute('outstanding_days', 0);
            $customer->setAttribute('oldest_unpaid_bill', null);

            return;
        }

        $oldest->ensureShareToken();
        $from = $oldest->admission_date?->startOfDay() ?? now()->startOfDay();
        $customer->setAttribute('outstanding_days', (int) $from->diffInDays(now()->startOfDay()));
        $customer->setAttribute('oldest_unpaid_bill', [
            'id' => $oldest->id,
            'bill_number' => $oldest->bill_number,
            'admission_date' => $oldest->admission_date?->toDateString(),
            'balance_due' => $oldest->balance_due,
            'share_token' => $oldest->share_token,
        ]);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $data = $request->validate([
            'name' => [$update ? 'sometimes' : 'nullable', 'string', 'max:255'],
            'phone' => [$update ? 'sometimes' : 'required', 'regex:/^[0-9+() -]{7,20}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'sms_opt_in' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            $data['name'] = 'Walk-in';
        }
        if (! $update && empty($data['name'])) {
            $data['name'] = 'Walk-in';
        }
        if (! $update && ! array_key_exists('sms_opt_in', $data)) {
            $data['sms_opt_in'] = true;
        }

        return $data;
    }
}
