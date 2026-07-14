<?php

namespace App\Services;

use App\Models\BrokerSettlement;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SalesItem;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

/**
 * Derived financial statements (Profit & Loss, Balance Sheet, Trial Balance).
 *
 * There is no general ledger in this system; these statements are read models
 * built the same way as {@see AccountingBookService} — by aggregating the
 * transactional tables and manual vouchers into standard account heads. They
 * are therefore INDICATIVE: a single balancing line ("Capital / difference in
 * books" on the Balance Sheet, "Suspense" on the Trial Balance) absorbs any gap
 * so the statements always foot, without pretending to be double-entry.
 *
 * All queries are anchored on tenant-scoped Eloquent models so the shared-DB
 * TenantScope applies; no raw cross-tenant DB::table scans.
 */
class FinancialStatementService
{
    public function __construct(private readonly AccountingBookService $books) {}

    // ---------------------------------------------------------------------
    // Profit & Loss (period)
    // ---------------------------------------------------------------------

    public function profitAndLoss(?string $from, ?string $to): array
    {
        $revenue = $this->salesRevenue($from, $to);
        $cogs = $this->cogs($from, $to);
        $grossProfit = round($revenue - $cogs, 2);

        $expenseRows = $this->expensesByCategory($from, $to);
        $brokerage = $this->brokerage($from, $to);

        $rows = [];
        $rows[] = $this->plRow('Income', 'Sales (net of tax)', $revenue);
        $rows[] = $this->plRow('Cost of Goods Sold', 'Cost of goods sold', $cogs);
        $rows[] = $this->plRow('Gross Profit', 'Gross Profit', $grossProfit);

        $totalExpenses = 0.0;
        foreach ($expenseRows as $e) {
            $rows[] = $this->plRow('Operating Expenses', $e['category'], (float) $e['total']);
            $totalExpenses += (float) $e['total'];
        }
        if ($brokerage > 0) {
            $rows[] = $this->plRow('Operating Expenses', 'Brokerage & Commission', $brokerage);
            $totalExpenses += $brokerage;
        }

        $netProfit = round($grossProfit - $totalExpenses, 2);
        $rows[] = $this->plRow('Net Profit', 'Net Profit', $netProfit);

        return [
            'data' => $rows,
            'meta' => [
                'revenue' => $revenue,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'total_expenses' => round($totalExpenses, 2),
                'net_profit' => $netProfit,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Balance Sheet (as of $to)
    // ---------------------------------------------------------------------

    public function balanceSheet(?string $asOf): array
    {
        $cash = (float) ($this->books->cashBook(null, $asOf)['summary']['closing_balance'] ?? 0);
        $bank = (float) ($this->books->bankBook(null, $asOf, null)['summary']['closing_balance'] ?? 0);
        $receivable = $this->receivables($asOf);
        $inventory = $this->inventoryValue();

        $payable = $this->payables($asOf);
        $gstPayable = round($this->outputTax(null, $asOf) - $this->inputTax(null, $asOf), 2);
        $tdsPayable = $this->tdsPayable(null, $asOf);
        $tcsPayable = $this->tcsPayable(null, $asOf);
        $retained = $this->netProfitToDate($asOf);

        $totalAssets = round($cash + $bank + $receivable + $inventory, 2);
        $knownLiabEquity = round($payable + $gstPayable + $tdsPayable + $tcsPayable + $retained, 2);
        // Balancing capital: whatever reconciles the two sides (owner funds,
        // opening equity, and the accumulated difference of a derived model).
        $capital = round($totalAssets - $knownLiabEquity, 2);

        $rows = [];
        $rows[] = $this->bsRow('Assets', 'Current Assets', 'Cash in Hand', $cash);
        $rows[] = $this->bsRow('Assets', 'Current Assets', 'Bank Accounts', $bank);
        $rows[] = $this->bsRow('Assets', 'Current Assets', 'Accounts Receivable (Debtors)', $receivable);
        $rows[] = $this->bsRow('Assets', 'Current Assets', 'Closing Stock (Inventory)', $inventory);

        $rows[] = $this->bsRow('Liabilities & Equity', 'Current Liabilities', 'Accounts Payable (Creditors)', $payable);
        $rows[] = $this->bsRow('Liabilities & Equity', 'Current Liabilities', 'GST Payable', $gstPayable);
        $rows[] = $this->bsRow('Liabilities & Equity', 'Current Liabilities', 'TDS Payable', $tdsPayable);
        $rows[] = $this->bsRow('Liabilities & Equity', 'Current Liabilities', 'TCS Payable', $tcsPayable);
        $rows[] = $this->bsRow('Liabilities & Equity', 'Equity', 'Reserves & Surplus (Retained Earnings)', $retained);
        $rows[] = $this->bsRow('Liabilities & Equity', 'Equity', 'Capital Account (balancing)', $capital);

        return [
            'data' => $rows,
            'meta' => [
                'total_assets' => $totalAssets,
                'total_liabilities_equity' => round($knownLiabEquity + $capital, 2),
                'as_of' => $asOf,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Trial Balance (flows over the period, balances as of $to)
    // ---------------------------------------------------------------------

    public function trialBalance(?string $from, ?string $to): array
    {
        $cash = (float) ($this->books->cashBook(null, $to)['summary']['closing_balance'] ?? 0);
        $bank = (float) ($this->books->bankBook(null, $to, null)['summary']['closing_balance'] ?? 0);
        $receivable = $this->receivables($to);
        $inventory = $this->inventoryValue();
        $revenue = $this->salesRevenue($from, $to);
        $cogs = $this->cogs($from, $to);
        $expenses = round(array_sum(array_column($this->expensesByCategory($from, $to), 'total')), 2);
        $brokerage = $this->brokerage($from, $to);
        $payable = $this->payables($to);
        $gstPayable = round($this->outputTax(null, $to) - $this->inputTax(null, $to), 2);
        $tdsPayable = $this->tdsPayable(null, $to);
        $tcsPayable = $this->tcsPayable(null, $to);

        // Debit balances (assets, expenses); credit balances (income, liabilities).
        $debits = [
            'Cash in Hand' => $cash,
            'Bank Accounts' => $bank,
            'Accounts Receivable' => $receivable,
            'Closing Stock' => $inventory,
            'Cost of Goods Sold' => $cogs,
            'Operating Expenses' => $expenses,
            'Brokerage & Commission' => $brokerage,
        ];
        $credits = [
            'Sales' => $revenue,
            'Accounts Payable' => $payable,
            'GST Payable' => $gstPayable,
            'TDS Payable' => $tdsPayable,
            'TCS Payable' => $tcsPayable,
        ];

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($debits as $name => $amt) {
            $rows[] = ['account' => $name, 'debit' => round($amt, 2), 'credit' => 0.0];
            $totalDebit += $amt;
        }
        foreach ($credits as $name => $amt) {
            $rows[] = ['account' => $name, 'debit' => 0.0, 'credit' => round($amt, 2)];
            $totalCredit += $amt;
        }

        // Suspense line absorbs the difference so the trial balance foots.
        $diff = round($totalDebit - $totalCredit, 2);
        if (abs($diff) >= 0.01) {
            $rows[] = $diff > 0
                ? ['account' => 'Suspense / Difference in books', 'debit' => 0.0, 'credit' => $diff]
                : ['account' => 'Suspense / Difference in books', 'debit' => abs($diff), 'credit' => 0.0];
        }

        $grandDebit = round($totalDebit + ($diff < 0 ? abs($diff) : 0), 2);
        $grandCredit = round($totalCredit + ($diff > 0 ? $diff : 0), 2);

        return [
            'data' => $rows,
            'meta' => [
                'total_debit' => $grandDebit,
                'total_credit' => $grandCredit,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Aggregate helpers (all tenant-scoped)
    // ---------------------------------------------------------------------

    /** Net sales (line totals excl. tax) for confirmed/non-cancelled orders. */
    private function salesRevenue(?string $from, ?string $to): float
    {
        return round((float) SalesItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_items.sales_order_id')
            ->where('sales_orders.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('sales_orders.order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sales_orders.order_date', '<=', $to))
            ->sum(DB::raw('sales_items.line_total - sales_items.tax_amount')), 2);
    }

    /** Cost of goods sold from the stock ledger (actual historical cost). */
    private function cogs(?string $from, ?string $to): float
    {
        return round((float) StockLedger::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'stock_ledgers.reference_id')
            ->where('stock_ledgers.type', 'sale')
            ->where('stock_ledgers.reference_type', SalesOrder::class)
            ->where('sales_orders.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('sales_orders.order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sales_orders.order_date', '<=', $to))
            ->sum(DB::raw('stock_ledgers.quantity_out * stock_ledgers.unit_cost')), 2);
    }

    /** @return array<int,array{category:string,total:float}> */
    private function expensesByCategory(?string $from, ?string $to): array
    {
        return Expense::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->where('expenses.status', 'approved')
            ->when($from, fn ($q) => $q->whereDate('expenses.expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expenses.expense_date', '<=', $to))
            ->selectRaw("COALESCE(expense_categories.name, 'Uncategorized') as category, SUM(expenses.amount) as total")
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => round((float) $r->total, 2)])
            ->all();
    }

    private function brokerage(?string $from, ?string $to): float
    {
        return round((float) BrokerSettlement::query()
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))
            ->sum('amount'), 2);
    }

    /** Debtors = opening + invoices − receipts − approved credit notes, as of the date. */
    private function receivables(?string $asOf): float
    {
        $opening = (float) Customer::query()->sum('opening_balance');
        $invoices = (float) Invoice::query()->when($asOf, fn ($q) => $q->whereDate('invoice_date', '<=', $asOf))->sum('total');
        $payments = (float) Payment::query()->when($asOf, fn ($q) => $q->whereDate('payment_date', '<=', $asOf))->sum('amount');
        $credits = (float) SalesReturn::query()->where('status', 'approved')
            ->when($asOf, fn ($q) => $q->whereDate('return_date', '<=', $asOf))->sum('total');

        return round($opening + $invoices - $payments - $credits, 2);
    }

    /** Creditors = opening + approved bills − payments, as of the date. */
    private function payables(?string $asOf): float
    {
        $opening = (float) Supplier::query()->sum('opening_balance');
        $invoices = (float) PurchaseInvoice::query()->where('status', 'approved')
            ->when($asOf, fn ($q) => $q->whereDate('invoice_date', '<=', $asOf))->sum('total');
        $payments = (float) SupplierPayment::query()
            ->when($asOf, fn ($q) => $q->whereDate('payment_date', '<=', $asOf))->sum('amount');

        return round($opening + $invoices - $payments, 2);
    }

    private function inventoryValue(): float
    {
        return round((float) WarehouseStock::query()->where('quantity', '>', 0)
            ->sum(DB::raw('quantity * average_cost')), 2);
    }

    private function outputTax(?string $from, ?string $to): float
    {
        return round((float) SalesItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_items.sales_order_id')
            ->where('sales_orders.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('sales_orders.order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sales_orders.order_date', '<=', $to))
            ->sum('sales_items.tax_amount'), 2);
    }

    private function inputTax(?string $from, ?string $to): float
    {
        return round((float) PurchaseInvoiceItem::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
            ->when($from, fn ($q) => $q->whereDate('purchase_invoices.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('purchase_invoices.invoice_date', '<=', $to))
            ->sum('purchase_invoice_items.tax_amount'), 2);
    }

    private function tdsPayable(?string $from, ?string $to): float
    {
        $purchase = (float) PurchaseInvoice::query()
            ->when($from, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('invoice_date', '<=', $to))->sum('tds_amount');
        $expense = (float) Expense::query()
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))->sum('tds_amount');
        $broker = (float) BrokerSettlement::query()
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))->sum('tds_amount');

        return round($purchase + $expense + $broker, 2);
    }

    private function tcsPayable(?string $from, ?string $to): float
    {
        return round((float) SalesOrder::query()->where('status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('order_date', '<=', $to))->sum('tcs_amount'), 2);
    }

    /** Accumulated net profit from inception up to the date (retained earnings). */
    private function netProfitToDate(?string $asOf): float
    {
        return $this->profitAndLoss(null, $asOf)['meta']['net_profit'];
    }

    private function plRow(string $section, string $particulars, float $amount): array
    {
        return ['section' => $section, 'particulars' => $particulars, 'amount' => round($amount, 2)];
    }

    private function bsRow(string $side, string $group, string $particulars, float $amount): array
    {
        return ['side' => $side, 'group' => $group, 'particulars' => $particulars, 'amount' => round($amount, 2)];
    }
}
