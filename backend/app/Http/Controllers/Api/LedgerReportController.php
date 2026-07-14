<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;

/**
 * Exportable party statements for the Reports module. The running-balance
 * computation deliberately mirrors CustomerController@ledger and
 * SupplierController@ledger (the authoritative on-screen ledgers) so the
 * figures reconcile: opening balance seeds the total and every row moves it by
 * (debit - credit). Invoices/bills are debits; receipts, payouts and credit
 * notes are credits. This controller adds the CSV / Excel / PDF export layer.
 */
class LedgerReportController extends Controller
{
    use ExportsReports;

    private const COLUMNS = [
        'date' => 'Date', 'type' => 'Type', 'reference' => 'Reference',
        'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit', 'balance' => 'Balance',
    ];

    /** Customer (receivable) ledger: invoices debit; payments and credit notes credit. */
    public function customer(Request $request)
    {
        $customer = Customer::findOrFail($request->query('customer_id'));
        [$from, $to] = $this->range($request);
        $window = fn ($q, string $col) => $q
            ->when($from, fn ($qq) => $qq->whereDate($col, '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate($col, '<=', $to));

        $entries = [];

        $window($customer->invoices(), 'invoice_date')
            ->get(['id', 'invoice_number', 'invoice_date', 'total'])
            ->each(function ($inv) use (&$entries) {
                $entries[] = $this->entry($inv->invoice_date, 'invoice', $inv->invoice_number, 'Sales Invoice', (float) $inv->total, 0.0);
            });

        $window(
            Payment::query()->whereIn('invoice_id', $customer->invoices()->select('id'))->with('invoice:id,invoice_number'),
            'payment_date'
        )->get(['id', 'invoice_id', 'amount', 'mode', 'payment_date'])
            ->each(function ($pay) use (&$entries) {
                $entries[] = $this->entry($pay->payment_date, 'payment', $pay->invoice?->invoice_number, 'Payment ('.$pay->mode.')', 0.0, (float) $pay->amount);
            });

        $window($customer->salesReturns()->where('status', 'approved'), 'return_date')
            ->get(['id', 'credit_note_number', 'return_date', 'total'])
            ->each(function ($cn) use (&$entries) {
                $entries[] = $this->entry($cn->return_date, 'credit_note', $cn->credit_note_number, 'Credit Note', 0.0, (float) $cn->total);
            });

        return $this->respond($request, $entries, (float) $customer->opening_balance, $customer->name, ! $from, 'customer-ledger');
    }

    /** Supplier (payable) ledger: approved purchase invoices debit; payments credit. */
    public function supplier(Request $request)
    {
        $supplier = Supplier::findOrFail($request->query('supplier_id'));
        [$from, $to] = $this->range($request);
        $window = fn ($q, string $col) => $q
            ->when($from, fn ($qq) => $qq->whereDate($col, '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate($col, '<=', $to));

        $entries = [];

        $window($supplier->purchaseInvoices()->where('status', 'approved'), 'invoice_date')
            ->get(['id', 'invoice_number', 'invoice_date', 'total'])
            ->each(function ($inv) use (&$entries) {
                $entries[] = $this->entry($inv->invoice_date, 'invoice', $inv->invoice_number, 'Purchase Invoice', (float) $inv->total, 0.0);
            });

        $window(
            SupplierPayment::query()->whereIn('purchase_invoice_id', $supplier->purchaseInvoices()->select('id'))->with('purchaseInvoice:id,invoice_number'),
            'payment_date'
        )->get(['id', 'purchase_invoice_id', 'amount', 'mode', 'payment_date'])
            ->each(function ($pay) use (&$entries) {
                $entries[] = $this->entry($pay->payment_date, 'payment', $pay->purchaseInvoice?->invoice_number, 'Payment ('.$pay->mode.')', 0.0, (float) $pay->amount);
            });

        return $this->respond($request, $entries, (float) $supplier->opening_balance, $supplier->name, ! $from, 'supplier-ledger');
    }

    private function entry($date, string $type, ?string $reference, string $description, float $debit, float $credit): array
    {
        return [
            'date' => optional($date)->toDateString(),
            'type' => $type,
            'reference' => $reference,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }

    /** Sort chronologically, accumulate the running balance and (optionally) prepend the opening row. */
    private function respond(Request $request, array $entries, float $opening, ?string $party, bool $withOpening, string $filename)
    {
        usort($entries, fn ($a, $b) => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        $running = round($opening, 2);
        foreach ($entries as &$entry) {
            $running = round($running + $entry['debit'] - $entry['credit'], 2);
            $entry['balance'] = $running;
        }
        unset($entry);

        $totalDebit = round(array_sum(array_column($entries, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($entries, 'credit')), 2);

        if ($withOpening) {
            array_unshift($entries, [
                'date' => null, 'type' => 'opening', 'reference' => null,
                'description' => 'Opening Balance', 'debit' => $opening, 'credit' => 0.0, 'balance' => $opening,
            ]);
        }

        return $this->exportResponse($request, $entries, self::COLUMNS, $filename, [
            'party' => $party,
            'opening_balance' => round($opening, 2),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $running,
        ]);
    }

    private function range(Request $request): array
    {
        return [
            $request->filled('date_from') ? $request->query('date_from') : null,
            $request->filled('date_to') ? $request->query('date_to') : null,
        ];
    }
}
