<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Branch;
use App\Support\BranchQuery;
use App\Support\ExpenseJobKind;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceReportController extends Controller
{
    private const PDF_MEMORY_BYTES = 1024 * 1024 * 1024;

    private const DETAIL_HEADERS = ['Date', 'Description', 'Reference / Bill #', 'Vehicle', 'Details', 'Category', 'Debit', 'Credit'];

    public function __invoke(Request $request, BalanceSheetController $sheets): Response|StreamedResponse
    {
        abort_unless(ExpenseJobKind::exportEnabledFor($request->user()), 403, 'Finance report download is not enabled for this business.');

        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'date' => ['nullable', 'date'],
            'format' => ['required', 'in:xlsx,pdf'],
            'type' => ['required', 'in:full,table'],
        ]);
        $month = (int) $data['month'];
        $year = (int) $data['year'];
        $full = $data['type'] === 'full';
        $day = isset($data['date']) ? \Illuminate\Support\Carbon::parse($data['date'])->toDateString() : null;

        $report = $sheets->monthlyReport($month, $year, $request->user());
        if ($day) {
            $report['accounts'] = array_values(array_filter(
                $report['accounts'],
                fn (array $row) => ($row['date'] ?? '') === $day,
            ));
            unset($report['expense_split']);
        }
        $days = $this->dailyRows($report['accounts']);
        if ($day) {
            $income = round(array_sum(array_column($days, 'credit')), 2);
            $expenses = round(array_sum(array_column($days, 'debit')), 2);
            $report['income'] = $income;
            $report['expenses'] = $expenses;
            $report['net_profit'] = round($income - $expenses, 2);
        }
        $from = $day ?? sprintf('%04d-%02d-01', $year, $month);
        $to = $day ?? \Illuminate\Support\Carbon::parse($from)->endOfMonth()->toDateString();
        $channels = $this->channelTotals($from, $to);
        $meta = [
            'business' => $request->user()->tenant?->business_name ?? 'Business',
            'period' => $day
                ? \Illuminate\Support\Carbon::parse($day)->format('j F Y')
                : now()->setDate($year, $month, 1)->format('F Y'),
            'heading' => $day ? 'Accounts' : 'Monthly accounts summary',
            'shop' => $this->shopName(),
            'full' => $full,
            'single_day' => $day !== null,
        ];
        $filename = $day
            ? sprintf('finance-%s-%s.%s', $day, $data['type'], $data['format'])
            : sprintf('finance-%04d-%02d-%s.%s', $year, $month, $data['type'], $data['format']);

        return $data['format'] === 'xlsx'
            ? $this->excel($report, $days, $channels, $meta, $filename)
            : $this->pdf($report, $days, $channels, $meta, $filename);
    }

    /**
     * Same day grouping as the Finance page: only income, expense and refund rows count toward debit/credit.
     *
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array{date: string, debit: float, credit: float, profit: float, entries: list<array<string, mixed>>}>
     */
    private function dailyRows(array $accounts): array
    {
        $days = [];
        foreach ($accounts as $row) {
            $date = (string) $row['date'];
            $days[$date] ??= ['date' => $date, 'debit' => 0.0, 'credit' => 0.0, 'entries' => []];
            $days[$date]['entries'][] = $row;
            if (in_array($row['type'], ['income', 'expense', 'refund'], true)) {
                $days[$date]['debit'] += (float) $row['debit'];
                $days[$date]['credit'] += (float) $row['credit'];
            }
        }

        return array_values(array_map(fn (array $day) => [
            ...$day,
            'debit' => round($day['debit'], 2),
            'credit' => round($day['credit'], 2),
            'profit' => round($day['credit'] - $day['debit'], 2),
        ], $days));
    }

    private function shopName(): ?string
    {
        $id = BranchQuery::idForRead();

        return $id ? Branch::query()->whereKey($id)->value('name') : null;
    }

    /**
     * @return list<array{0: string, 1: float}>
     */
    private function summaryLines(array $report): array
    {
        $lines = [
            ['Income', (float) $report['income']],
            ['Expenses', (float) $report['expenses']],
            ['Net profit', (float) $report['net_profit']],
        ];
        if (isset($report['expense_split'])) {
            $lines[] = ['Repair expenses', (float) $report['expense_split']['repair']];
            $lines[] = ['Service expenses', (float) $report['expense_split']['service']];
            $lines[] = ['Inventory purchases', (float) $report['expense_split']['inventory']];
            $lines[] = ['Other expenses (untagged, salaries)', (float) $report['expense_split']['other']];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|float>
     */
    private function detailCells(array $row): array
    {
        return [
            (string) $row['date'],
            (string) $row['description'],
            (string) ($row['reference'] ?? ''),
            (string) ($row['vehicle'] ?? ''),
            (string) ($row['details'] ?? ''),
            (string) $row['category'],
            (float) $row['debit'],
            (float) $row['credit'],
        ];
    }

    /**
     * @return list<array{label: string, amount: float, color: string, font: string}>
     */
    private function channelTotals(string $from, string $to): array
    {
        $bills = BranchQuery::constrain(Bill::query())
            ->whereDate('admission_date', '>=', $from)
            ->whereDate('admission_date', '<=', $to)
            ->get(['subtotal', 'vat_amount', 'sscl_amount', 'balance_due', 'amount_paid', 'customer_balance']);

        $allSales = 0.0;
        $credit = 0.0;
        foreach ($bills as $bill) {
            $allSales += (float) $bill->balance_due + (float) $bill->amount_paid - (float) $bill->customer_balance;
            $credit += max(0, (float) $bill->balance_due);
        }

        $payments = BranchQuery::constrainViaBill(BillPayment::query()->countingTowardPaid())
            ->whereDate('paid_at', '>=', $from)
            ->whereDate('paid_at', '<=', $to)
            ->get(['amount', 'method']);

        $cash = 0.0;
        $bank = 0.0;
        $card = 0.0;
        $cheque = 0.0;
        foreach ($payments as $payment) {
            $amount = (float) $payment->amount;
            match ($payment->method) {
                'cash' => $cash += $amount,
                'card' => $card += $amount,
                'cheque' => $cheque += $amount,
                'bank_transfer' => $bank += $amount,
                default => null,
            };
        }

        $rows = [
            ['label' => 'All sales', 'amount' => round($allSales, 2), 'color' => '20221F', 'font' => 'FFFFFF'],
            ['label' => 'Bank sales', 'amount' => round($bank, 2), 'color' => '1D4E89', 'font' => 'FFFFFF'],
            ['label' => 'Credit sales', 'amount' => round($credit, 2), 'color' => 'B84837', 'font' => 'FFFFFF'],
            ['label' => 'Cash sales', 'amount' => round($cash, 2), 'color' => '167C73', 'font' => 'FFFFFF'],
        ];
        if (abs($card) > 0.001) {
            $rows[] = ['label' => 'Card sales', 'amount' => round($card, 2), 'color' => '8A6A12', 'font' => 'FFFFFF'];
        }
        if (abs($cheque) > 0.001) {
            $rows[] = ['label' => 'Cheque sales', 'amount' => round($cheque, 2), 'color' => '5C4B32', 'font' => 'FFFFFF'];
        }

        return $rows;
    }

    private function excel(array $report, array $days, array $channels, array $meta, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Monthly summary');

        $sheet->setCellValue('A1', $meta['business']);
        $sheet->setCellValue('A2', $meta['heading'].' · '.$meta['period'].($meta['shop'] ? ' · '.$meta['shop'] : ''));
        $sheet->getStyle('A1:A2')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getFont()->setSize(14);

        $row = 4;
        foreach ($this->summaryLines($report) as [$label, $amount]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $amount);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $row++;
        $headerRow = $row;
        $sheet->fromArray(['Date', 'Debit (expenses)', 'Credit (income)', 'Daily profit'], null, "A{$row}");
        $this->styleHeader($sheet, "A{$row}:D{$row}");
        foreach ($days as $day) {
            $row++;
            $sheet->fromArray([$day['date'], $day['debit'], $day['credit'], $day['profit']], null, "A{$row}");
        }
        $row++;
        $sheet->fromArray([$meta['single_day'] ? "Day totals" : 'Monthly totals', (float) $report['expenses'], (float) $report['income'], (float) $report['net_profit']], null, "A{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $row = $this->writeChannelFooter($sheet, $channels, $row);
        $sheet->getStyle("B4:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'D') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A'.($headerRow + 1));

        if ($meta['full']) {
            $detail = $spreadsheet->createSheet();
            $detail->setTitle('Day details');
            $detail->fromArray(self::DETAIL_HEADERS, null, 'A1');
            $this->styleHeader($detail, 'A1:H1');
            $line = 1;
            foreach ($days as $day) {
                foreach ($day['entries'] as $entry) {
                    $line++;
                    $detail->fromArray($this->detailCells($entry), null, "A{$line}");
                }
            }
            $detail->getStyle("G2:H{$line}")->getNumberFormat()->setFormatCode('#,##0.00');
            $this->writeChannelFooter($detail, $channels, $line, 'H');
            foreach (range('A', 'H') as $column) {
                $detail->getColumnDimension($column)->setAutoSize(true);
            }
            $detail->freezePane('A2');
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  list<array{label: string, amount: float, color: string, font: string}>  $channels
     */
    private function writeChannelFooter(Worksheet $sheet, array $channels, int $row, string $amountColumn = 'D'): int
    {
        $labelEnd = chr(ord($amountColumn) - 1);
        foreach ($channels as $channel) {
            $row++;
            $sheet->setCellValue("A{$row}", $channel['label']);
            $sheet->mergeCells("A{$row}:{$labelEnd}{$row}");
            $sheet->setCellValue("{$amountColumn}{$row}", $channel['amount']);
            $range = "A{$row}:{$amountColumn}{$row}";
            $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB($channel['font']);
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($channel['color']);
            $sheet->getStyle("{$amountColumn}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        return $row;
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('167C73');
    }

    private function pdf(array $report, array $days, array $channels, array $meta, string $filename): Response
    {
        $this->raiseLimitsForPdf();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->pdfHtml($report, $days, $channels, $meta), 'UTF-8');
        $dompdf->setPaper('A4', $meta['full'] ? 'landscape' : 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * dompdf keeps the whole layout in memory, so a busy month's full report outgrows PHP-FPM's usual 128M.
     */
    private function raiseLimitsForPdf(): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit !== '-1' && $this->toBytes($limit) < self::PDF_MEMORY_BYTES) {
            ini_set('memory_limit', (string) self::PDF_MEMORY_BYTES);
        }
        set_time_limit(300);
    }

    private function toBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function pdfHtml(array $report, array $days, array $channels, array $meta): string
    {
        $e = fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $m = fn ($value): string => number_format((float) $value, 2);

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #20221f; }
            h1 { font-size: 16px; margin: 0; }
            h2 { font-size: 12px; margin: 16px 0 6px; }
            p.sub { margin: 2px 0 10px; color: #4f544e; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
            th, td { border: 1px solid #d7d3c8; padding: 4px 6px; vertical-align: top; }
            th { background: #167c73; color: #fff; text-align: left; font-size: 9px; text-transform: uppercase; }
            td.num, th.num { text-align: right; white-space: nowrap; }
            tr.total td { font-weight: bold; background: #f3f0e8; }
            table.summary { width: auto; }
            table.summary td { border: none; padding: 2px 12px 2px 0; }
            .day-head { background: #eeece5; font-weight: bold; }
            table.channels { width: auto; margin-top: 12px; }
            table.channels td { border: none; color: #fff; font-weight: bold; padding: 6px 14px; }
        </style></head><body>';

        $html .= '<h1>'.$e($meta['business']).'</h1>';
        $html .= '<p class="sub">'.$e($meta['heading']).' · '.$e($meta['period']).($meta['shop'] ? ' · '.$e($meta['shop']) : '').'</p>';

        $html .= '<table class="summary">';
        foreach ($this->summaryLines($report) as [$label, $amount]) {
            $html .= '<tr><td><strong>'.$e($label).'</strong></td><td class="num">LKR '.$m($amount).'</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h2>Daily accounts</h2><table><thead><tr><th>Date</th><th class="num">Debit (expenses)</th><th class="num">Credit (income)</th><th class="num">Daily profit</th></tr></thead><tbody>';
        foreach ($days as $day) {
            $html .= '<tr><td>'.$e($day['date']).'</td><td class="num">'.$m($day['debit']).'</td><td class="num">'.$m($day['credit']).'</td><td class="num">'.$m($day['profit']).'</td></tr>';
        }
        if ($days === []) {
            $html .= '<tr><td colspan="4">No income or expense entries for this month.</td></tr>';
        }
        $totalsLabel = $meta['single_day'] ? "Day totals" : 'Monthly totals';
        $html .= '<tr class="total"><td>'.$e($totalsLabel).'</td><td class="num">'.$m($report['expenses']).'</td><td class="num">'.$m($report['income']).'</td><td class="num">'.$m($report['net_profit']).'</td></tr>';
        $html .= $this->channelFooterHtml($channels, $e, $m);
        $html .= '</tbody></table>';
        $html .= '<p class="sub">All sales is the total of bills opened in this period. Bank sales and cash sales are money received. Credit sales is the amount still owed on those bills.</p>';

        if ($meta['full']) {
            $html .= '<h2>Day details</h2>';
            foreach ($days as $day) {
                $html .= '<table><thead>';
                $html .= '<tr class="day-head"><td colspan="7">'.$e($day['date']).' · Debit '.$m($day['debit']).' · Credit '.$m($day['credit']).' · Profit '.$m($day['profit']).'</td></tr>';
                $html .= '<tr><th>Description</th><th>Reference / Bill #</th><th>Vehicle</th><th>Details</th><th>Category</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>';
                foreach ($day['entries'] as $entry) {
                    $html .= '<tr><td>'.$e($entry['description']).'</td><td>'.$e($entry['reference'] ?? '—').'</td><td>'.$e($entry['vehicle'] ?? '—').'</td><td>'.$e($entry['details'] ?? '—').'</td><td>'.$e($entry['category']).'</td>'
                        .'<td class="num">'.((float) $entry['debit'] > 0 ? $m($entry['debit']) : '—').'</td>'
                        .'<td class="num">'.((float) $entry['credit'] > 0 ? $m($entry['credit']) : '—').'</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '<table>'.$this->channelFooterHtml($channels, $e, $m).'</table>';
        }

        return $html.'</body></html>';
    }

    /**
     * @param  list<array{label: string, amount: float, color: string, font: string}>  $channels
     */
    private function channelFooterHtml(array $channels, callable $e, callable $m): string
    {
        $html = '';
        foreach ($channels as $channel) {
            $style = 'background:#'.$channel['color'].';color:#'.$channel['font'].';font-weight:bold;border-color:#'.$channel['color'];
            $html .= '<tr><td colspan="3" style="'.$style.'">'.$e($channel['label']).'</td><td class="num" style="'.$style.'">LKR '.$m($channel['amount']).'</td></tr>';
        }

        return $html;
    }
}
