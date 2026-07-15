<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;

/**
 * Chat-based ERP Search + the intent engine behind the Gujarati Voice Assistant
 * (SRS §22). A natural-language question (typed, or transcribed from speech) is
 * routed to the right entity/metric by keyword matching in both English and
 * Gujarati, and answered with a spoken-friendly sentence plus structured rows.
 *
 * It is intentionally deterministic and offline — no model call is required to
 * answer "આજનું વેચાણ કેટલું?" ("how much are today's sales?"). When a hosted
 * provider is configured it is used only to rephrase the answer, never to fetch
 * data (which always comes from tenant-scoped queries here).
 */
class ErpSearchService
{
    public function __construct(private readonly InventoryIntelligenceService $inventory = new InventoryIntelligenceService) {}

    /**
     * @return array{query:string, intent:string, answer:string, type:string, results:list<array<string,mixed>>, suggestions?:list<string>}
     */
    public function answer(string $query): array
    {
        $q = trim($query);
        $lc = mb_strtolower($q);

        if ($q === '') {
            return $this->help($q);
        }

        // Order matters: check the most specific metric intents before the
        // generic entity lookups.
        return match (true) {
            $this->matches($lc, ['low stock', 'out of stock', 'stockout', 'reorder', 'ઓછો સ્ટોક', 'સ્ટોક ખતમ', 'ખૂટ']) => $this->lowStock($q),
            $this->matches($lc, ['outstanding', 'receivable', 'due', 'pending payment', 'udhar', 'બાકી', 'ઉધાર', 'લેણું']) => $this->outstanding($q),
            $this->matches($lc, ['top product', 'best selling', 'best seller', 'top selling', 'સૌથી વધુ વેચાણ', 'બેસ્ટ સેલર']) => $this->topProducts($q),
            $this->matches($lc, ['sale', 'sales', 'revenue', 'turnover', 'વેચાણ', 'આવક']) => $this->salesTotal($q, $lc),
            $this->matches($lc, ['invoice', 'bill', 'બિલ', 'ઇન્વોઇસ']) => $this->invoiceLookup($q, $lc),
            $this->matches($lc, ['customer', 'ગ્રાહક', 'કસ્ટમર']) => $this->customerSearch($q, $lc),
            $this->matches($lc, ['supplier', 'vendor', 'સપ્લાયર', 'વેપારી', 'સપ્લાયર']) => $this->supplierSearch($q, $lc),
            $this->matches($lc, ['product', 'item', 'sku', 'stock of', 'પ્રોડક્ટ', 'કાપડ', 'માલ', 'સ્ટોક']) => $this->productSearch($q, $lc),
            default => $this->fallback($q),
        };
    }

    // ---------------------------------------------------------------- intents

    private function salesTotal(string $q, string $lc): array
    {
        [$from, $to, $label] = $this->resolvePeriod($lc);

        $total = (float) Invoice::query()
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total');
        $count = Invoice::query()
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $cur = Setting::currencySymbol();
        $answer = sprintf('%s sales: %s%s across %d invoice(s).', ucfirst($label), $cur, number_format($total, 2), $count);

        return $this->respond($q, 'sales_total', $answer, 'metric', [[
            'label' => ucfirst($label).' sales',
            'amount' => round($total, 2),
            'invoices' => $count,
        ]]);
    }

    private function outstanding(string $q): array
    {
        $rows = Invoice::query()
            ->whereIn('status', ['unpaid', 'partial'])
            ->with('customer:id,name')
            ->get(['id', 'invoice_number', 'customer_id', 'total', 'paid_amount', 'credited_amount', 'due_date', 'status']);

        $byCustomer = [];
        foreach ($rows as $inv) {
            $bal = max(0, (float) $inv->total - (float) $inv->paid_amount - (float) $inv->credited_amount);
            if ($bal <= 0) {
                continue;
            }
            $name = $inv->customer?->name ?? 'Unknown';
            $byCustomer[$name] = ($byCustomer[$name] ?? 0) + $bal;
        }
        arsort($byCustomer);

        $cur = Setting::currencySymbol();
        $total = array_sum($byCustomer);
        $results = [];
        foreach (array_slice($byCustomer, 0, 15, true) as $name => $bal) {
            $results[] = ['customer' => $name, 'outstanding' => round($bal, 2)];
        }

        $answer = $total > 0
            ? sprintf('Total outstanding is %s%s across %d customer(s). Top: %s (%s%s).',
                $cur, number_format($total, 2), count($byCustomer),
                array_key_first($byCustomer), $cur, number_format(reset($byCustomer), 2))
            : 'No outstanding receivables — all invoices are settled.';

        return $this->respond($q, 'outstanding', $answer, 'table', $results);
    }

    private function lowStock(string $q): array
    {
        $prediction = $this->inventory->lowStockPrediction();
        $items = array_slice($prediction['items'], 0, 15);

        $results = array_map(fn ($i) => [
            'sku' => $i['product']['sku'],
            'name' => $i['product']['name'],
            'on_hand' => $i['on_hand'],
            'days_to_stockout' => $i['days_to_stockout'],
            'projected_stockout_date' => $i['projected_stockout_date'],
            'risk' => $i['risk'],
        ], $items);

        $answer = $items === []
            ? 'No products are at immediate stockout risk based on recent sales velocity.'
            : sprintf('%d product(s) at stockout risk. Most urgent: %s runs out in about %s day(s).',
                count($prediction['items']), $items[0]['product']['name'], $items[0]['days_to_stockout']);

        return $this->respond($q, 'low_stock', $answer, 'table', $results);
    }

    private function topProducts(string $q): array
    {
        $rows = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.type', 'sale')
            ->where('stock_ledgers.created_at', '>=', now()->subDays(30))
            ->selectRaw('products.sku, products.name, SUM(stock_ledgers.quantity_out) as qty')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('qty')
            ->limit(10)
            ->get();

        $results = $rows->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'qty_sold' => round((float) $r->qty, 2)])->all();

        $answer = $results === []
            ? 'No sales recorded in the last 30 days.'
            : 'Top sellers (last 30 days): '.implode(', ', array_map(fn ($r) => "{$r['name']} ({$r['qty_sold']})", array_slice($results, 0, 5))).'.';

        return $this->respond($q, 'top_products', $answer, 'table', $results);
    }

    private function invoiceLookup(string $q, string $lc): array
    {
        $term = $this->extractTerm($lc, ['invoice', 'bill', 'બિલ', 'ઇન્વોઇસ', 'number', 'no', 'for']);

        $query = Invoice::query()->with('customer:id,name');
        if ($term !== '') {
            $query->where(fn ($sub) => $sub
                ->where('invoice_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")));
        }
        $rows = $query->latest('invoice_date')->limit(15)->get();

        $cur = Setting::currencySymbol();
        $results = $rows->map(fn ($inv) => [
            'invoice_number' => $inv->invoice_number,
            'customer' => $inv->customer?->name,
            'date' => optional($inv->invoice_date)->toDateString(),
            'total' => round((float) $inv->total, 2),
            'balance' => round(max(0, (float) $inv->total - (float) $inv->paid_amount - (float) $inv->credited_amount), 2),
            'status' => $inv->status,
        ])->all();

        $answer = $results === []
            ? 'No matching invoices found.'
            : sprintf('Found %d invoice(s)%s. Latest: %s for %s%s.',
                count($results), $term !== '' ? " matching \"{$term}\"" : '',
                $results[0]['invoice_number'], $cur, number_format($results[0]['total'], 2));

        return $this->respond($q, 'invoice_lookup', $answer, 'table', $results);
    }

    private function customerSearch(string $q, string $lc): array
    {
        $term = $this->extractTerm($lc, ['customer', 'ગ્રાહક', 'કસ્ટમર', 'find', 'search', 'show', 'details', 'of', 'for']);

        $rows = Customer::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$term}%")
                ->orWhere('company_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")))
            ->limit(15)
            ->get(['id', 'code', 'name', 'company_name', 'phone', 'city', 'credit_limit']);

        $results = $rows->map(fn ($c) => [
            'code' => $c->code,
            'name' => $c->name,
            'company' => $c->company_name,
            'phone' => $c->phone,
            'city' => $c->city,
        ])->all();

        $answer = $results === []
            ? "No customers found matching \"{$term}\"."
            : sprintf('Found %d customer(s)%s.', count($results), $term !== '' ? " matching \"{$term}\"" : '');

        return $this->respond($q, 'customer_search', $answer, 'table', $results);
    }

    private function supplierSearch(string $q, string $lc): array
    {
        $term = $this->extractTerm($lc, ['supplier', 'vendor', 'સપ્લાયર', 'વેપારી', 'find', 'search', 'show', 'of', 'for']);

        $rows = Supplier::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$term}%")
                ->orWhere('company_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")))
            ->limit(15)
            ->get(['id', 'code', 'name', 'company_name', 'phone', 'city']);

        $results = $rows->map(fn ($s) => [
            'code' => $s->code,
            'name' => $s->name,
            'company' => $s->company_name,
            'phone' => $s->phone,
            'city' => $s->city,
        ])->all();

        $answer = $results === []
            ? "No suppliers found matching \"{$term}\"."
            : sprintf('Found %d supplier(s)%s.', count($results), $term !== '' ? " matching \"{$term}\"" : '');

        return $this->respond($q, 'supplier_search', $answer, 'table', $results);
    }

    private function productSearch(string $q, string $lc): array
    {
        $term = $this->extractTerm($lc, ['product', 'item', 'sku', 'stock of', 'stock', 'પ્રોડક્ટ', 'કાપડ', 'માલ', 'સ્ટોક', 'find', 'search', 'show', 'of', 'for', 'how much']);

        $products = Product::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%")
                ->orWhere('hsn_code', 'like', "%{$term}%")))
            ->limit(15)
            ->get(['id', 'sku', 'name', 'selling_price', 'cost_price', 'reorder_level']);

        $onHand = WarehouseStock::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $cur = Setting::currencySymbol();
        $results = $products->map(fn ($p) => [
            'sku' => $p->sku,
            'name' => $p->name,
            'on_hand' => round((float) ($onHand[$p->id] ?? 0), 3),
            'selling_price' => round((float) $p->selling_price, 2),
        ])->all();

        $answer = $results === []
            ? "No products found matching \"{$term}\"."
            : (count($results) === 1
                ? sprintf('%s: %s in stock, sells at %s%s.', $results[0]['name'], $results[0]['on_hand'], $cur, number_format($results[0]['selling_price'], 2))
                : sprintf('Found %d product(s)%s.', count($results), $term !== '' ? " matching \"{$term}\"" : ''));

        return $this->respond($q, 'product_search', $answer, 'table', $results);
    }

    private function fallback(string $q): array
    {
        // Last resort: try a broad product name match before giving up.
        $products = Product::query()
            ->where('name', 'like', "%{$q}%")
            ->orWhere('sku', 'like', "%{$q}%")
            ->limit(10)
            ->get(['id', 'sku', 'name', 'selling_price']);

        if ($products->isNotEmpty()) {
            return $this->productSearch($q, mb_strtolower($q));
        }

        return $this->help($q);
    }

    private function help(string $q): array
    {
        return $this->respond(
            $q,
            'help',
            "I can answer questions about your business. Try: \"today's sales\", \"low stock items\", "
            .'"outstanding payments", "top selling products", "find customer Sharma", or "stock of cotton shirt". '
            .'ગુજરાતીમાં પણ પૂછો: "આજનું વેચાણ", "ઓછો સ્ટોક", "બાકી રકમ".',
            'help',
            [],
            [
                "Today's sales",
                'This month sales',
                'Low stock items',
                'Outstanding payments',
                'Top selling products',
            ],
        );
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<string> $needles */
    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a period phrase to a [from, to, label] window.
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable,2:string}
     */
    private function resolvePeriod(string $lc): array
    {
        $now = CarbonImmutable::now();

        if ($this->matches($lc, ['today', 'આજ'])) {
            return [$now->startOfDay(), $now->endOfDay(), "today's"];
        }
        if ($this->matches($lc, ['yesterday', 'ગઈકાલ'])) {
            $y = $now->subDay();

            return [$y->startOfDay(), $y->endOfDay(), "yesterday's"];
        }
        if ($this->matches($lc, ['this week', 'આ અઠવાડિય'])) {
            return [$now->startOfWeek(), $now->endOfWeek(), "this week's"];
        }
        if ($this->matches($lc, ['last month', 'ગયા મહિન', 'ગયા મહિને'])) {
            $m = $now->subMonth();

            return [$m->startOfMonth(), $m->endOfMonth(), "last month's"];
        }
        if ($this->matches($lc, ['this year', 'આ વર્ષ'])) {
            return [$now->startOfYear(), $now->endOfYear(), "this year's"];
        }

        // Default: this month.
        return [$now->startOfMonth(), $now->endOfMonth(), "this month's"];
    }

    /**
     * Strip known keywords/stopwords to isolate a free-text search term.
     *
     * @param  list<string>  $keywords
     */
    private function extractTerm(string $lc, array $keywords): string
    {
        $stop = array_merge($keywords, ['the', 'a', 'an', 'me', 'my', 'is', 'are', 'what', 'which', 'give', 'list', 'show', 'find', 'search', 'get', 'see', 'details', 'named', 'called', 'મને', 'બતાવ', 'છે', 'નું', 'ના', 'ની', 'શોધ']);

        // Longest keywords first so multi-word phrases ("stock of") are removed
        // before their constituent words. Space-pad so replacement is whole-word
        // only — otherwise a stopword like "a" would gut names like "Sharma".
        usort($stop, fn ($x, $y) => mb_strlen($y) <=> mb_strlen($x));

        $term = ' '.preg_replace('/\s+/u', ' ', $lc).' ';
        foreach ($stop as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            $term = str_ireplace(' '.$word.' ', '  ', $term);
        }

        return trim(preg_replace('/\s+/u', ' ', $term));
    }

    /**
     * @param  list<array<string,mixed>>  $results
     * @param  list<string>  $suggestions
     * @return array{query:string, intent:string, answer:string, type:string, results:list<array<string,mixed>>, suggestions?:list<string>}
     */
    private function respond(string $query, string $intent, string $answer, string $type, array $results, array $suggestions = []): array
    {
        $out = [
            'query' => $query,
            'intent' => $intent,
            'answer' => $answer,
            'type' => $type,
            'results' => $results,
        ];
        if ($suggestions !== []) {
            $out['suggestions'] = $suggestions;
        }

        return $out;
    }
}
