<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\BrokerSettlement;
use App\Models\Expense;
use App\Models\PurchaseInvoice;
use App\Models\SalesOrder;
use Illuminate\Http\Request;

/**
 * Section-wise TDS and TCS registers.
 *  - tds → tax we deducted at source on purchase bills, expenses and broker
 *          commission settlements.
 *  - tcs → tax we collected at source on sales.
 *
 * Both read the tds_amount / tcs_amount columns captured on the source
 * documents; nothing is written here.
 */
class TaxRegisterController extends Controller
{
    use ExportsReports;

    private const TDS_COLUMNS = [
        'date' => 'Date', 'section' => 'Section', 'party' => 'Party', 'document' => 'Document',
        'base_amount' => 'Base Amount', 'rate' => 'Rate %', 'tds_amount' => 'TDS Amount',
    ];

    private const TCS_COLUMNS = [
        'date' => 'Date', 'section' => 'Section', 'party' => 'Party', 'document' => 'Document',
        'base_amount' => 'Base Amount', 'rate' => 'Rate %', 'tcs_amount' => 'TCS Amount',
    ];

    public function tds(Request $request)
    {
        [$from, $to] = [$request->query('date_from'), $request->query('date_to')];
        $entries = [];

        PurchaseInvoice::query()->with('supplier:id,name')->where('tds_amount', '>', 0)
            ->when($from, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('invoice_date', '<=', $to))
            ->get()->each(function ($inv) use (&$entries) {
                $entries[] = $this->tdsEntry($inv->invoice_date, $inv->tds_section, $inv->supplier?->name, $inv->invoice_number, (float) $inv->total, (float) $inv->tds_rate, (float) $inv->tds_amount);
            });

        Expense::query()->with('category:id,name')->where('tds_amount', '>', 0)
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->get()->each(function ($e) use (&$entries) {
                $entries[] = $this->tdsEntry($e->expense_date, $e->tds_section, $e->payee ?: $e->category?->name, $e->expense_number, (float) $e->amount, (float) $e->tds_rate, (float) $e->tds_amount);
            });

        BrokerSettlement::query()->with('broker:id,name')->where('tds_amount', '>', 0)
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))
            ->get()->each(function ($s) use (&$entries) {
                $entries[] = $this->tdsEntry($s->settlement_date, '194H', $s->broker?->name, $s->settlement_number, (float) $s->amount, (float) $s->tds_percent, (float) $s->tds_amount);
            });

        usort($entries, fn ($a, $b) => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        return $this->exportResponse($request, $entries, self::TDS_COLUMNS, 'tds-register', [
            'total_tds' => round(array_sum(array_column($entries, 'tds_amount')), 2),
            'count' => count($entries),
        ]);
    }

    public function tcs(Request $request)
    {
        [$from, $to] = [$request->query('date_from'), $request->query('date_to')];

        $entries = SalesOrder::query()->with('customer:id,name')
            ->where('status', '!=', 'cancelled')->where('tcs_amount', '>', 0)
            ->when($from, fn ($q) => $q->whereDate('order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('order_date', '<=', $to))
            ->orderBy('order_date')
            ->get()->map(fn ($so) => [
                'date' => optional($so->order_date)->toDateString(),
                'section' => $so->tcs_section,
                'party' => $so->customer?->name,
                'document' => $so->so_number,
                'base_amount' => (float) $so->total,
                'rate' => (float) $so->tcs_rate,
                'tcs_amount' => (float) $so->tcs_amount,
            ])->all();

        return $this->exportResponse($request, $entries, self::TCS_COLUMNS, 'tcs-register', [
            'total_tcs' => round(array_sum(array_column($entries, 'tcs_amount')), 2),
            'count' => count($entries),
        ]);
    }

    private function tdsEntry($date, ?string $section, ?string $party, ?string $document, float $base, float $rate, float $tds): array
    {
        return [
            'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date ? (string) $date : null),
            'section' => $section,
            'party' => $party,
            'document' => $document,
            'base_amount' => round($base, 2),
            'rate' => round($rate, 2),
            'tds_amount' => round($tds, 2),
        ];
    }
}
