<?php

namespace App\Http\Controllers;

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
            'format' => ['required', 'in:xlsx,pdf'],
            'type' => ['required', 'in:full,table'],
        ]);
        $month = (int) $data['month'];
        $year = (int) $data['year'];
        $full = $data['type'] === 'full';

        $report = $sheets->monthlyReport($month, $year, $request->user());
        $days = $this->dailyRows($report['accounts']);
        $meta = [
            'business' => $request->user()->tenant?->business_name ?? 'Business',
            'period' => now()->setDate($year, $month, 1)->format('F Y'),
            'shop' => $this->shopName(),
            'full' => $full,
        ];
        $filename = sprintf('finance-%04d-%02d-%s.%s', $year, $month, $data['type'], $data['format']);

        return $data['format'] === 'xlsx'
            ? $this->excel($report, $days, $meta, $filename)
            : $this->pdf($report, $days, $meta, $filename);
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

    private function excel(array $report, array $days, array $meta, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Monthly summary');

        $sheet->setCellValue('A1', $meta['business']);
        $sheet->setCellValue('A2', 'Monthly accounts summary · '.$meta['period'].($meta['shop'] ? ' · '.$meta['shop'] : ''));
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
        $sheet->fromArray(['Monthly totals', (float) $report['expenses'], (float) $report['income'], (float) $report['net_profit']], null, "A{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
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

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('167C73');
    }

    private function pdf(array $report, array $days, array $meta, string $filename): Response
    {
        $this->raiseLimitsForPdf();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->pdfHtml($report, $days, $meta), 'UTF-8');
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

    private function pdfHtml(array $report, array $days, array $meta): string
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
        </style></head><body>';

        $html .= '<h1>'.$e($meta['business']).'</h1>';
        $html .= '<p class="sub">Monthly accounts summary · '.$e($meta['period']).($meta['shop'] ? ' · '.$e($meta['shop']) : '').'</p>';

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
        $html .= '<tr class="total"><td>Monthly totals</td><td class="num">'.$m($report['expenses']).'</td><td class="num">'.$m($report['income']).'</td><td class="num">'.$m($report['net_profit']).'</td></tr>';
        $html .= '</tbody></table>';

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
        }

        return $html.'</body></html>';
    }
}
