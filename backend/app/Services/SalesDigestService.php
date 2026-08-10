<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SalesItem;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

/**
 * The day's trading, in one figure set (retail base §8c).
 *
 * Built as a service rather than inside the command because the same numbers are
 * wanted in three places — the scheduled digest, a "send it to me now" button,
 * and the phone push — and three copies of the same SQL would drift.
 *
 * What it deliberately reports, and why: an owner reading a message on the way
 * home wants to know what came in, how it was paid, what went back out, and
 * whether anything needs doing tomorrow. It does not want a P&L. Every figure
 * here answers one of those four questions and nothing else was added.
 */
class SalesDigestService
{
    /**
     * @return array{
     *     date:string, sales:array, payments:array, returns:array,
     *     top_items:array, purchases:array, customers:array, attention:array
     * }
     */
    public function forDate(string $date): array
    {
        $invoices = Invoice::query()->whereDate('invoice_date', $date);
        $billed = round((float) (clone $invoices)->sum('total'), 2);
        $billCount = (int) (clone $invoices)->count();

        // Money that actually arrived today, which is not the same as what was
        // billed today — a credit sale bills now and pays in three weeks.
        $payments = Payment::query()->whereDate('payment_date', $date);
        $collected = round((float) (clone $payments)->sum('amount'), 2);

        /**
         * Split by tender. Read from the enum rather than a fixed list of four,
         * so a shop running vouchers or an exchange tender sees them here without
         * this file being edited — `payments.mode` grows over time (see the
         * pos-reversal-paths note) and a hard-coded split would quietly hide the
         * newest one.
         */
        $byMode = (clone $payments)
            ->selectRaw('mode, SUM(amount) as total, COUNT(*) as c')
            ->groupBy('mode')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->mode => ['total' => round((float) $r->total, 2), 'count' => (int) $r->c]])
            ->all();

        $returns = SalesReturn::query()->whereDate('return_date', $date)->where('status', 'approved');
        $returnValue = round((float) (clone $returns)->sum('total'), 2);
        $returnCount = (int) (clone $returns)->count();

        return [
            'date' => $date,
            'sales' => [
                'billed' => $billed,
                'bills' => $billCount,
                'average_bill' => $billCount > 0 ? round($billed / $billCount, 2) : 0.0,
                // Net of what came back — the number an owner actually means by
                // "what did we do today".
                'net' => round($billed - $returnValue, 2),
            ],
            'payments' => [
                'collected' => $collected,
                'by_mode' => $byMode,
                // Billed but not collected today: the credit the shop extended.
                'on_credit' => round(max($billed - $collected, 0), 2),
            ],
            'returns' => ['value' => $returnValue, 'count' => $returnCount],
            'top_items' => $this->topItems($date),
            'purchases' => $this->purchases($date),
            'customers' => [
                'new' => (int) Customer::query()->whereDate('created_at', $date)->count(),
            ],
            'attention' => $this->attention(),
            'currency' => Setting::currencySymbol(),
        ];
    }

    /** The five articles that moved. What a buyer looks at first. */
    private function topItems(string $date): array
    {
        return SalesItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_items.sales_order_id')
            ->join('products', 'products.id', '=', 'sales_items.product_id')
            ->whereDate('sales_orders.order_date', $date)
            ->where('sales_orders.status', '!=', 'cancelled')
            ->selectRaw('products.name, products.sku,
                SUM(sales_items.quantity) as qty,
                SUM(sales_items.line_total) as value')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc(DB::raw('SUM(sales_items.line_total)'))
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'sku' => $r->sku,
                'quantity' => round((float) $r->qty, 3),
                'value' => round((float) $r->value, 2),
            ])
            ->all();
    }

    /**
     * What went out the other way, so the day reads both sides.
     *
     * Through the model, not DB::table: the tenant scope lives on the Eloquent
     * model, and a raw query builder here would total every tenant's purchases
     * into one shop's digest.
     */
    private function purchases(string $date): array
    {
        $bills = PurchaseInvoice::query()->whereDate('invoice_date', $date);

        return [
            'bills' => (int) (clone $bills)->count(),
            'value' => round((float) (clone $bills)->sum('total'), 2),
        ];
    }

    /**
     * Tomorrow's work, not today's takings. Two counts only: the shop has other
     * screens for the detail, and a digest that lists twelve worklists is a
     * digest nobody opens twice.
     */
    private function attention(): array
    {
        // Same shape as the dashboard's Low Stock tile, deliberately: two screens
        // giving a shop two different low-stock counts is worse than either.
        $lowStock = (int) WarehouseStock::query()
            ->whereHas('product', function ($q) {
                $q->whereColumn('reorder_level', '>=', 'warehouse_stocks.quantity')->where('reorder_level', '>', 0);
            })
            ->count();

        $overdue = (int) Invoice::query()
            ->whereDate('due_date', '<', now()->toDateString())
            ->where('status', '!=', 'paid')
            ->count();

        return ['low_stock' => $lowStock, 'overdue_invoices' => $overdue];
    }

    /** True when there is anything worth sending. Silence beats a zero digest. */
    public function isWorthSending(array $digest): bool
    {
        return $digest['sales']['bills'] > 0
            || $digest['returns']['count'] > 0
            || $digest['purchases']['bills'] > 0;
    }

    /** One-line summary, for an SMS or a push notification body. */
    public function oneLine(array $digest): string
    {
        $symbol = $digest['currency'];
        $s = $digest['sales'];
        $p = $digest['payments'];

        $line = "{$s['bills']} bill(s), {$symbol}".number_format($s['net'], 2)
            .' net · collected '.$symbol.number_format($p['collected'], 2);

        if ($digest['returns']['count'] > 0) {
            $line .= ' · '.$digest['returns']['count'].' return(s)';
        }
        if ($digest['attention']['low_stock'] > 0) {
            $line .= ' · '.$digest['attention']['low_stock'].' low on stock';
        }

        return $line;
    }
}
