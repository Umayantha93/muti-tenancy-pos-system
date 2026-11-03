<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Support\BranchQuery;
use App\Support\BusinessTypes;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OweInReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->assertGarage($request);
        $data = $this->dates($request);
        $query = $this->filtered($data['date_from'] ?? null, $data['date_to'] ?? null);
        $total = round((float) (clone $query)->sum('balance_due'), 2);
        $page = $query
            ->with(['customer:id,name,phone', 'vehicle:id,number_plate'])
            ->orderBy('owe_in_due_date')
            ->orderBy('id')
            ->paginate(min(50, max(1, $request->integer('per_page', 15))));

        return response()->json([
            'receivables_total' => $total,
            'count' => $page->total(),
            'data' => collect($page->items())->map(fn (Bill $bill) => $this->row($bill))->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function pdf(Request $request): Response
    {
        $this->assertGarage($request);
        $data = $this->dates($request);
        $from = $data['date_from'] ?? null;
        $to = $data['date_to'] ?? null;
        $bills = $this->filtered($from, $to)
            ->with(['customer:id,name,phone', 'vehicle:id,number_plate'])
            ->orderBy('owe_in_due_date')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        $rows = $bills->map(fn (Bill $bill) => $this->row($bill));
        $total = round((float) $rows->sum('amount'), 2);
        $business = $request->user()->tenant?->business_name ?? 'Garage';
        $filename = 'owe-in'.($from ? '-'.$from : '').($to ? '-to-'.$to : '').'.pdf';

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($business, $from, $to, $rows, $total), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array{date_from?: string, date_to?: string}
     */
    private function dates(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }

    private function filtered(?string $from, ?string $to): Builder
    {
        return BranchQuery::constrain(Bill::query())
            ->where('status', 'owe_in')
            ->where('balance_due', '>', 0)
            ->when($from, fn (Builder $query) => $query->whereDate('owe_in_due_date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('owe_in_due_date', '<=', $to));
    }

    /**
     * @return array{bill_id: int, bill_number: string, customer_name: string, phone: ?string, vehicle_number: ?string, amount: float, due_date: ?string}
     */
    private function row(Bill $bill): array
    {
        return [
            'bill_id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'customer_name' => $bill->customer?->name ?: 'Walk-in',
            'phone' => $bill->customer?->phone,
            'vehicle_number' => $bill->vehicle?->number_plate,
            'amount' => round((float) $bill->balance_due, 2),
            'due_date' => $bill->owe_in_due_date?->toDateString(),
        ];
    }

    private function assertGarage(Request $request): void
    {
        $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? ''));
        abort_unless($type === BusinessTypes::GARAGE, 404);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{customer_name: string, phone: ?string, vehicle_number: ?string, amount: float}>  $rows
     */
    private function html(string $business, ?string $from, ?string $to, $rows, float $total): string
    {
        $e = fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $money = fn ($value): string => number_format((float) $value, 2);
        $period = match (true) {
            $from && $to => $from.' to '.$to,
            $from => 'from '.$from,
            $to => 'up to '.$to,
            default => 'All open owe-in',
        };

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #20221f; }
            h1 { font-size: 16px; margin: 0; }
            p.sub { margin: 4px 0 12px; color: #4f544e; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #d7d3c8; padding: 5px 6px; }
            th { background: #167c73; color: #fff; text-align: left; font-size: 9px; text-transform: uppercase; }
            td.num, th.num { text-align: right; white-space: nowrap; }
            tr.total td { font-weight: bold; background: #f3f0e8; }
        </style></head><body>';
        $html .= '<h1>'.$e($business).'</h1>';
        $html .= '<p class="sub">Customer owe-in · '.$e($period).'</p>';
        $html .= '<table><thead><tr><th>Name</th><th>Phone</th><th>Vehicle</th><th class="num">Amount</th></tr></thead><tbody>';
        if ($rows->isEmpty()) {
            $html .= '<tr><td colspan="4">No customers owe in this range.</td></tr>';
        }
        foreach ($rows as $row) {
            $html .= '<tr><td>'.$e($row['customer_name']).'</td><td>'.$e($row['phone'] ?: '—').'</td><td>'.$e($row['vehicle_number'] ?: '—').'</td><td class="num">'.$money($row['amount']).'</td></tr>';
        }
        $html .= '<tr class="total"><td colspan="3">Total</td><td class="num">'.$money($total).'</td></tr>';
        $html .= '</tbody></table></body></html>';

        return $html;
    }
}
