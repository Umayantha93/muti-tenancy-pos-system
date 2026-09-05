<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\CottageRoom;
use App\Models\CottageStay;
use App\Models\Customer;
use App\Support\BranchQuery;
use App\Support\BusinessTypes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CottageStayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stays = CottageStay::query()
            ->with(['customer', 'room', 'bill.items', 'bill.payments'])
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('check_out', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('check_in', '<=', $request->date('to')))
            ->latest('check_in')
            ->paginate($request->integer('per_page', 15));

        return $this->moneyJson($stays);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'cottage_room_id' => ['required', Rule::exists('cottage_rooms', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['reserved', 'checked_in', 'checked_out', 'cancelled'])],
            'create_bill' => ['sometimes', 'boolean'],
        ]);

        $stay = DB::transaction(function () use ($data, $request) {
            $room = CottageRoom::findOrFail($data['cottage_room_id']);
            $overlap = CottageStay::query()
                ->where('cottage_room_id', $room->id)
                ->whereNotIn('status', ['cancelled', 'checked_out'])
                ->where('check_in', '<', $data['check_out'])
                ->where('check_out', '>', $data['check_in'])
                ->exists();
            if ($overlap) {
                abort(response()->json(['message' => 'Room is not available for those dates.'], 422));
            }

            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null],
            );
            $customer->update(['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? $customer->address]);

            $stay = CottageStay::create([
                'customer_id' => $customer->id,
                'cottage_room_id' => $room->id,
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'guests' => $data['guests'] ?? 1,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? 'reserved',
                'created_by' => $request->user()->id,
                'branch_id' => $room->branch_id,
            ]);

            if ($request->boolean('create_bill', true)) {
                $nights = max(1, Carbon::parse($data['check_in'])->diffInDays(Carbon::parse($data['check_out'])));
                $total = round($room->nightly_rate * $nights, 2);
                $bill = Bill::create([
                    'bill_number' => BusinessTypes::billPrefix(BusinessTypes::COTTAGE).'-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
                    'customer_id' => $customer->id,
                    'admission_date' => $data['check_in'],
                    'notes' => $data['notes'] ?? null,
                    'source_type' => CottageStay::class,
                    'source_id' => $stay->id,
                    'subtotal' => $total,
                    'balance_due' => $total,
                    'created_by' => $request->user()->id,
                    'branch_id' => $room->branch_id,
                ]);
                BillItem::create([
                    'bill_id' => $bill->id,
                    'type' => 'room',
                    'description' => "{$room->name} · {$nights} night(s)",
                    'quantity' => $nights,
                    'unit_price' => $room->nightly_rate,
                    'line_total' => $total,
                ]);
                $stay->update(['bill_id' => $bill->id]);
            }

            if (($data['status'] ?? 'reserved') === 'checked_in') {
                $room->update(['status' => 'occupied']);
            }

            return $stay->load(['customer', 'room', 'bill.items']);
        });

        return response()->json($stay, 201);
    }

    public function show(CottageStay $stay): JsonResponse
    {
        return $this->moneyJson($stay->load(['customer', 'room', 'bill.items', 'bill.payments', 'creator']));
    }

    public function update(Request $request, CottageStay $stay): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['sometimes', 'date'],
            'check_out' => ['sometimes', 'date', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['reserved', 'checked_in', 'checked_out', 'cancelled'])],
        ]);
        $stay->update($data);

        if (isset($data['status'])) {
            $room = $stay->room;
            if ($data['status'] === 'checked_in') {
                $room->update(['status' => 'occupied']);
            } elseif (in_array($data['status'], ['checked_out', 'cancelled'], true)) {
                $room->update(['status' => 'available']);
            }
        }

        return response()->json($stay->refresh()->load(['customer', 'room', 'bill']));
    }

    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);
        $month = (int) ($data['month'] ?? now()->month);
        $year = (int) ($data['year'] ?? now()->year);
        $from = Carbon::create($year, $month)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month)->endOfMonth()->toDateString();

        $rooms = BranchQuery::constrain(CottageRoom::query())->orderBy('name')->get(['id', 'name', 'status']);
        $stays = BranchQuery::constrain(CottageStay::query())
            ->with(['customer:id,name', 'room:id,name'])
            ->whereNotIn('status', ['cancelled'])
            ->whereDate('check_out', '>', $from)
            ->whereDate('check_in', '<=', $to)
            ->get();

        return response()->json([
            'month' => $month,
            'year' => $year,
            'from' => $from,
            'to' => $to,
            'rooms' => $rooms,
            'stays' => $stays,
        ]);
    }
}
