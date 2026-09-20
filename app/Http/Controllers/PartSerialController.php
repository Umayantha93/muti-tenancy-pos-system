<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PartSerial;
use App\Services\BranchInventory;
use App\Services\PartSerials;
use App\Support\BranchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartSerialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:in_stock,sold,returned'],
            'part_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = PartSerial::normalize((string) ($data['search'] ?? ''));

        $rows = PartSerial::query()
            ->with([
                'part:id,name,sku,brand,price,serialized',
                'billItem:id,bill_id,description,warranty_until',
                'billItem.bill:id,bill_number,customer_id',
                'billItem.bill.customer:id,name,phone',
            ])
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->when($search !== '', fn ($query) => $query->where('serial', 'like', '%'.$search.'%'))
            ->when(! empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! empty($data['part_id']), fn ($query) => $query->where('part_id', $data['part_id']))
            ->latest('id')
            ->paginate(min(100, max(1, (int) ($data['per_page'] ?? 50))));

        return response()->json($rows);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:80'],
        ]);
        $serial = PartSerial::normalize($data['q']);
        $row = PartSerial::query()
            ->with([
                'part:id,name,sku,brand,price,stock_qty,serialized',
                'billItem:id,bill_id,description,warranty_months,warranty_until',
                'billItem.bill:id,bill_number,customer_id,status',
                'billItem.bill.customer:id,name,phone',
            ])
            ->where('serial', $serial)
            ->first();

        abort_unless($row, 404, 'No IMEI / serial matched.');

        return response()->json($row);
    }

    public function store(Request $request, Part $part): JsonResponse
    {
        $data = $request->validate([
            'serials' => ['required', 'array', 'min:1'],
            'serials.*' => ['required', 'string', 'max:40'],
        ]);

        [$created, $fresh] = DB::transaction(function () use ($part, $data) {
            $created = PartSerials::receive($part, $data['serials']);
            BranchInventory::addPart($part, $created->count());

            return [$created, $part->fresh()];
        });

        return response()->json([
            'part' => BranchInventory::overlayPart($fresh),
            'created' => $created->count(),
            'serials' => $created,
        ], 201);
    }
}
