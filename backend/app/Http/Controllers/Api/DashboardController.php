<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerFeedback;
use App\Models\DashboardLayout;
use App\Models\Invoice;
use App\Models\JobWork;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockLedger;
use App\Models\WarehouseStock;
use App\Modules\Industry\Manufacturing\Models\Machine;
use App\Modules\Industry\Manufacturing\Models\MachineDowntime;
use App\Modules\Industry\Manufacturing\Models\ProductionScrap;
use App\Modules\Industry\Manufacturing\Models\QualityInspection;
use App\Support\TenantCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** How long the computed dashboard payload is cached per tenant (seconds). */
    private const CACHE_TTL = 300;

    /**
     * The catalogue of dashboard widgets (project-stack.md §5). Each maps to a
     * key the frontend knows how to render from the kpis payload. This is also
     * the default layout (all visible, in this order) for a brand-new user.
     */
    public const WIDGETS = [
        'today_sales', 'purchase_summary', 'pending_orders', 'profit_loss',
        'low_stock', 'out_of_stock', 'total_stock_value', 'products_count',
        /**
         * Customer satisfaction (retail base §1, §6). A stat tile rather than a
         * panel: the number an owner wants on the way past is "are people happy
         * with us", and the detail — who said what, and what about — is a click
         * away at /customer-feedback.
         *
         * Position here only sets the *default* layout for a user who has never
         * customised theirs. Anybody with a saved layout gets it appended hidden
         * (reconcile), so nobody's dashboard silently rearranges itself — they
         * switch it on when they want it.
         */
        'customer_csat',
        /**
         * The factory floor's own tiles (`.claude/shops/_base-factory.md` §1). A
         * works does not open its day on "Today's Sales" — it opens on what is on
         * the machines, what came off them, and what the scrap bin cost.
         *
         * Listed in the shared catalogue rather than behind a separate factory
         * dashboard, because the widget layout is already per-user and reconcile()
         * appends anything new *hidden*: a kirana never sees these unless somebody
         * switches them on, and a factory switches them on once. A second
         * dashboard endpoint would have been a second thing to keep in step.
         *
         * They compute to zeroes with no `manufacturing` module — the queries hit
         * empty tables, which costs a few index probes and no correctness.
         */
        'production_wip', 'production_today', 'machine_downtime',
        'qc_rejection', 'scrap_value', 'job_work_outstanding',
        'orders_in_hand', 'receivables', 'payables',
        'top_products', 'recent_activities',
    ];

    /**
     * Return the current user's saved widget layout, or the default catalogue
     * (everything visible) when they have not customised it yet.
     */
    public function layout(Request $request): JsonResponse
    {
        $saved = DashboardLayout::where('user_id', $request->user()->id)->first();

        if ($saved) {
            return response()->json(['widgets' => $this->reconcile($saved->widgets)]);
        }

        return response()->json(['widgets' => $this->defaultLayout()]);
    }

    /**
     * Persist the user's widget order + visibility. Unknown keys are ignored and
     * any missing catalogue widgets are appended (hidden) so the layout always
     * covers the full set.
     */
    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'widgets' => ['required', 'array'],
            'widgets.*.key' => ['required', 'string'],
            'widgets.*.visible' => ['required', 'boolean'],
        ]);

        $clean = collect($data['widgets'])
            ->filter(fn ($w) => in_array($w['key'], self::WIDGETS, true))
            ->map(fn ($w) => ['key' => $w['key'], 'visible' => (bool) $w['visible']])
            ->values()
            ->all();

        $widgets = $this->reconcile($clean);

        DashboardLayout::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['widgets' => $widgets],
        );

        return response()->json(['widgets' => $widgets]);
    }

    /**
     * The factory floor's tiles (`.claude/shops/_base-factory.md` §1).
     *
     * Every figure here answers a question a works asks before it asks anything
     * about money: what is on the machines, what came off them last night, what
     * stopped, what got rejected, what the bin cost, and what is sitting at a
     * job-work vendor. Receivables and payables close the list because they are
     * the two the base file names alongside them — and they are useful to a shop
     * too, which is why they are computed for everybody.
     *
     * One deliberate omission from the base file's list: **raw-material shortfall
     * against open orders**. Doing that honestly means exploding every open order's
     * BOM — multi-level, with wastage — and netting it against stock *and* against
     * what is already on order. That is the purchase-suggestion engine's job
     * (`/ai-insights`), it is far too slow for a five-minute-cached dashboard, and
     * a shortfall figure that quietly ignored open POs would send a factory out to
     * buy material it had already bought. Better a tile that is not here than one
     * that is wrong.
     *
     * @return array<string, mixed>
     */
    private function factoryKpis(string $today, $monthStart, $monthEnd): array
    {
        // --- What is on the machines right now ---
        $wip = ManufacturingOrder::query()->where('status', 'in_progress');
        $wipCount = (int) (clone $wip)->count();
        $wipQty = (float) (clone $wip)->sum('quantity_to_produce');
        // Money committed to the floor: what has been spent on orders still open.
        $wipValue = (float) (clone $wip)->sum('total_cost');

        // --- What came off them ---
        $producedToday = (float) ManufacturingOrder::query()
            ->whereDate('completed_at', $today)
            ->sum('quantity_produced');
        $completedToday = (int) ManufacturingOrder::query()->whereDate('completed_at', $today)->count();

        // --- What stopped ---
        $downtimeMinutes = (int) MachineDowntime::query()
            ->whereBetween('started_at', [$monthStart, $monthEnd])
            ->sum('minutes');
        $machinesDown = (int) Machine::query()
            ->where('is_active', true)
            ->whereIn('status', ['breakdown', 'maintenance'])
            ->count();
        $machinesActive = (int) Machine::query()->where('is_active', true)->count();

        // --- What got rejected ---
        $qc = QualityInspection::query()->whereBetween('inspected_on', [$monthStart, $monthEnd]);
        $accepted = (float) (clone $qc)->sum('accepted_quantity');
        $rejected = (float) (clone $qc)->sum('rejected_quantity');
        $inspected = $accepted + $rejected;

        // --- What the bin cost ---
        $scrap = ProductionScrap::query()->whereBetween('scrap_date', [$monthStart, $monthEnd]);
        $scrapValue = (float) (clone $scrap)->sum('total_value');
        // Netted, because scrap sold back is money the works genuinely recovers.
        $scrapRecoverable = (float) (clone $scrap)->sum('recoverable_value');

        // --- What is out at a vendor ---
        $jobWork = JobWork::query()->whereNotIn('status', ['completed', 'cancelled']);
        $jobWorkCount = (int) (clone $jobWork)->count();
        $jobWorkOverdue = (int) (clone $jobWork)
            ->whereNotNull('expected_date')
            ->whereDate('expected_date', '<', $today)
            ->count();

        // --- The order book, and the two ledger balances ---
        $ordersInHand = SalesOrder::query()->whereIn('status', ['draft', 'confirmed', 'backordered']);

        return [
            'production_wip' => [
                'orders' => $wipCount,
                'quantity' => round($wipQty, 3),
                'value' => round($wipValue, 2),
            ],
            'production_today' => [
                'quantity' => round($producedToday, 3),
                'orders_completed' => $completedToday,
            ],
            'machine_downtime' => [
                'minutes' => $downtimeMinutes,
                'hours' => round($downtimeMinutes / 60, 1),
                'machines_down' => $machinesDown,
                'machines_active' => $machinesActive,
                'period' => 'This month',
            ],
            'qc_rejection' => [
                // Null, not zero, when nothing was inspected — a works that ran no
                // inspections has no rejection rate, and showing 0% would read as
                // a perfect month.
                'percent' => $inspected > 0 ? round($rejected / $inspected * 100, 2) : null,
                'inspected' => round($inspected, 3),
                'rejected' => round($rejected, 3),
                'period' => 'This month',
            ],
            'scrap_value' => [
                'value' => round($scrapValue, 2),
                'recoverable' => round($scrapRecoverable, 2),
                'net' => round($scrapValue - $scrapRecoverable, 2),
                'period' => 'This month',
            ],
            'job_work_outstanding' => [
                'count' => $jobWorkCount,
                'overdue' => $jobWorkOverdue,
            ],
            'orders_in_hand' => [
                'count' => (int) (clone $ordersInHand)->count(),
                'value' => round((float) (clone $ordersInHand)->sum('total'), 2),
            ],
            /**
             * Receivables and payables, computed in SQL rather than by summing
             * outstandingBalance() over every party: a distributor with four
             * thousand customers would otherwise run four thousand queries to
             * paint one tile.
             */
            'receivables' => [
                'total' => round((float) Invoice::query()
                    ->whereIn('status', ['unpaid', 'partial'])
                    ->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as due')
                    ->value('due'), 2),
                'overdue' => round((float) Invoice::query()
                    ->whereIn('status', ['unpaid', 'partial'])
                    ->whereDate('due_date', '<', $today)
                    ->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as due')
                    ->value('due'), 2),
            ],
            /**
             * Note the column: a purchase invoice's `status` is its *approval*
             * state (pending / approved / cancelled) and `payment_status` is what
             * is owed on it. They are not the same word here as on a sales
             * invoice, and reading `status` would have totalled every unapproved
             * bill as a debt. Cancelled bills are excluded outright.
             */
            'payables' => [
                'total' => round((float) PurchaseInvoice::query()
                    ->whereIn('payment_status', ['unpaid', 'partial'])
                    ->where('status', '!=', 'cancelled')
                    ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as due')
                    ->value('due'), 2),
                'overdue' => round((float) PurchaseInvoice::query()
                    ->whereIn('payment_status', ['unpaid', 'partial'])
                    ->where('status', '!=', 'cancelled')
                    ->whereDate('due_date', '<', $today)
                    ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as due')
                    ->value('due'), 2),
            ],
        ];
    }

    /** Default layout: every widget, visible, in catalogue order. */
    private function defaultLayout(): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'visible' => true], self::WIDGETS);
    }

    /**
     * Ensure a stored layout still covers the whole catalogue: keep the saved
     * order, drop stale keys, append any newly-added widgets (hidden).
     *
     * @param  array<int,array{key:string,visible:bool}>  $saved
     */
    private function reconcile(array $saved): array
    {
        $known = collect($saved)->keyBy('key');
        $result = [];

        foreach ($saved as $w) {
            if (in_array($w['key'], self::WIDGETS, true)) {
                $result[] = ['key' => $w['key'], 'visible' => (bool) $w['visible']];
            }
        }
        foreach (self::WIDGETS as $key) {
            if (! $known->has($key)) {
                $result[] = ['key' => $key, 'visible' => false];
            }
        }

        return $result;
    }

    /**
     * All headline metrics for the landing dashboard (current tenant).
     *
     * The payload is a dozen-plus aggregate queries, so it is cached per tenant
     * (key "tenant_{id}_dashboard") for a few minutes rather than recomputed on
     * every page load. The short TTL bounds staleness without needing every
     * sale/purchase write to invalidate the cache.
     */
    public function kpis()
    {
        return response()->json(
            TenantCache::remember('dashboard', self::CACHE_TTL, fn () => $this->buildKpis())
        );
    }

    /** Compute the full dashboard metrics payload for the current tenant. */
    private function buildKpis(): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        // 1. Today's Sales — billed revenue + invoice count for today.
        $todaySales = Invoice::query()->whereDate('invoice_date', $today);
        $todaySalesTotal = (float) (clone $todaySales)->sum('total');
        $todaySalesCount = (clone $todaySales)->count();

        // 2. Purchase Summary — this month's POs + count of open/pending POs.
        $monthPo = PurchaseOrder::query()->whereBetween('order_date', [$monthStart, $monthEnd]);
        $purchaseMonthTotal = (float) (clone $monthPo)->sum('total');
        $purchaseMonthCount = (clone $monthPo)->count();
        $pendingPoCount = PurchaseOrder::query()
            ->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received'])
            ->count();

        // 3. Pending Orders — open sales + open purchase orders.
        $pendingSalesCount = SalesOrder::query()->whereIn('status', ['draft', 'confirmed'])->count();

        // 5. Low Stock Alert — on-hand at or below a positive reorder level.
        $lowStockCount = WarehouseStock::query()
            ->whereHas('product', function ($q) {
                $q->whereColumn('reorder_level', '>=', 'warehouse_stocks.quantity')->where('reorder_level', '>', 0);
            })
            ->count();

        // 6. Out of Stock — distinct products with no on-hand quantity anywhere.
        $outOfStockCount = WarehouseStock::query()
            ->where('quantity', '<=', 0)
            ->distinct('product_id')
            ->count('product_id');

        // 8. Profit & Loss (this month): revenue − COGS. No expenses module yet,
        //    so this is gross profit; expenses will be deducted once available.
        $revenue = (float) Invoice::query()->whereBetween('invoice_date', [$monthStart, $monthEnd])->sum('total');
        $cogs = (float) StockLedger::query()
            ->where('type', 'sale')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('SUM(quantity_out * unit_cost) as c')
            ->value('c');

        // 9. Top Selling Products (last 30 days, by quantity sold).
        $topProducts = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.type', 'sale')
            ->where('stock_ledgers.created_at', '>=', now()->subDays(30))
            ->selectRaw('products.sku, products.name, SUM(stock_ledgers.quantity_out) as qty_sold')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'qty_sold' => (float) $r->qty_sold])
            ->all();

        // 10. Recent Activities (tenant-scoped audit log).
        $recentActivities = ActivityLog::query()
            ->with('causer:id,name')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn ($a) => [
                'description' => $a->description,
                'event' => $a->event,
                'log_name' => $a->log_name,
                'causer' => $a->causer?->name,
                'at' => $a->created_at?->toIso8601String(),
            ])
            ->all();

        $totalStockValue = (float) WarehouseStock::query()
            ->selectRaw('SUM(quantity * average_cost) as total')
            ->value('total');

        // 11. Sales Trend — billed revenue per day for the last 14 days. Days
        //     with no invoices are backfilled with 0 so the chart is a full,
        //     continuous series (never a broken/empty line for a new tenant).
        $trendStart = now()->subDays(13)->startOfDay();
        $trendRows = Invoice::query()
            ->whereDate('invoice_date', '>=', $trendStart->toDateString())
            ->selectRaw('DATE(invoice_date) as d, SUM(total) as t, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('t', 'd');
        $trendCounts = Invoice::query()
            ->whereDate('invoice_date', '>=', $trendStart->toDateString())
            ->selectRaw('DATE(invoice_date) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');
        /**
         * Customer satisfaction, last 30 days. Reuses CustomerFeedback::csat() so
         * the tile and the /customer-feedback screen can never disagree about
         * where the satisfied boundary sits — a dashboard quoting 4-and-above
         * while the register quoted 3-and-above would be the worst kind of bug,
         * because both look right.
         */
        $csat = CustomerFeedback::csat(
            CustomerFeedback::query()->where('submitted_at', '>=', now()->subDays(30)),
        ) + [
            'unanswered_complaints' => (int) CustomerFeedback::query()
                ->where('status', 'submitted')
                ->where('rating', '<=', CustomerFeedback::DETRACTOR_UPTO)
                ->count(),
        ];

        $factory = $this->factoryKpis($today, $monthStart, $monthEnd);

        $salesTrend = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $trendStart->copy()->addDays($i);
            $key = $day->toDateString();
            $salesTrend[] = [
                'date' => $key,
                'label' => $day->format('d M'),
                'total' => round((float) ($trendRows[$key] ?? 0), 2),
                'count' => (int) ($trendCounts[$key] ?? 0),
            ];
        }

        return [
            'today_sales' => ['total' => round($todaySalesTotal, 2), 'count' => $todaySalesCount],
            'purchase_summary' => [
                'month_total' => round($purchaseMonthTotal, 2),
                'month_count' => $purchaseMonthCount,
                'pending_count' => $pendingPoCount,
            ],
            'pending_orders' => [
                'sales' => $pendingSalesCount,
                'purchases' => $pendingPoCount,
                'total' => $pendingSalesCount + $pendingPoCount,
            ],
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'profit_loss' => [
                'revenue' => round($revenue, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($revenue - $cogs, 2),
                'period' => 'This month',
            ],
            /**
             * Customer satisfaction over the last 30 days (retail base §6).
             *
             * A rolling window rather than "this month", because on the 2nd of a
             * month a calendar figure is built from two answers and reads as a
             * collapse. `csat_percent` is null until somebody has actually
             * answered — the tile then says "no answers yet" instead of 0%, which
             * would be a shop libelling itself.
             */
            'customer_csat' => $csat,
            // The factory floor's tiles, spread into the payload under their own
            // widget keys (see factoryKpis).
            ...$factory,
            'top_products' => $topProducts,
            'recent_activities' => $recentActivities,
            'sales_trend' => $salesTrend,

            // Retained headline metrics.
            'total_stock_value' => round($totalStockValue, 2),
            'products_count' => Product::query()->count(),
            // Kept for backward compatibility with the previous UI.
            'pending_po_count' => $pendingPoCount,
            'top_skus' => $topProducts,
        ];
    }
}
