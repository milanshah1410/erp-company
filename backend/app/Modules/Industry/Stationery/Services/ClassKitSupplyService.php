<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Services;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\WarehouseStock;
use App\Modules\Industry\Stationery\Models\ClassKit;
use App\Modules\Industry\Stationery\Models\ClassKitItem;
use App\Services\DocumentPricingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning a booklist into money — the one operation this vertical exists for.
 *
 * Everything downstream of a kit is the same three steps: work out what the lines
 * cost *this* buyer, say honestly what is on the shelf, and then write an
 * ordinary sales document. Keeping all three here rather than in the controllers
 * is what stops the counter's estimate and the school's quotation quietly
 * disagreeing about the price of the same list — which is exactly what happens
 * when a shop quotes a tender from a spreadsheet and bills from a till.
 *
 * Three rules the rest of the module depends on:
 *
 * **Pricing is not reimplemented here.** Quantity slabs already exist and are
 * already resolved by `Customer::priceFor()` — customer override, then the
 * product's price-list tier at that quantity, then the base price. A school
 * ordering four hundred of something is a bulk sale and gets the bulk rate for
 * free, and adding a second slab table here would produce a price the POS did not
 * agree with. That is the whole of "Bulk Sales": it was already built.
 *
 * **Availability is reported, never enforced.** In June a kit routinely has three
 * lines short, and the shop still sells it — with the shortfall on the delivery
 * note. A service that refused to price an incomplete list would be unusable for
 * ten weeks of the year, so the shortfall travels with the estimate and the
 * decision stays with the person at the counter.
 *
 * **The output is a core document.** A quotation is a `SalesQuotation` and an
 * order is a `SalesOrder` on the `institution` channel, so the tender, the
 * receivable, the GST and the partial delivery are all things that already work.
 */
class ClassKitSupplyService
{
    public function __construct(
        private readonly DocumentPricingService $pricing,
    ) {}

    /**
     * Price a booklist for one buyer, and say what is actually on the shelf.
     *
     * `$sets` is how many copies of the list — one for a parent, four hundred for
     * a school — and it is what makes the slab pricing bite: the same line is a
     * retail pen at ×1 and a wholesale carton at ×400.
     *
     * @param  list<int>|null  $onlyItemIds  Limit to these kit lines; null takes the required ones.
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     header: array<string, mixed>,
     *     shortfalls: list<array<string, mixed>>,
     *     discount_percent: float,
     * }
     */
    public function estimate(
        ClassKit $kit,
        ?Customer $customer = null,
        float $sets = 1,
        ?int $warehouseId = null,
        bool $includeOptional = false,
        ?array $onlyItemIds = null,
        bool $buyerIsTheInstitution = false,
    ): array {
        $kit->loadMissing(['items.product', 'items.variant', 'institution']);

        $selected = $kit->items
            ->when(
                $onlyItemIds !== null,
                fn ($items) => $items->whereIn('id', $onlyItemIds),
                // Without an explicit selection, a mandatory set takes everything
                // and an ordinary list takes only what the school insists on.
                fn ($items) => $includeOptional ? $items : $kit->requiredItems(),
            )
            ->values();

        if ($selected->isEmpty()) {
            throw new RuntimeException("Booklist {$kit->kit_number} has no lines to supply.");
        }

        $onHand = $this->onHand($selected, $warehouseId);
        $shortfalls = [];
        $items = [];

        foreach ($selected as $item) {
            /** @var ClassKitItem $item */
            $product = $item->product;

            if (! $product) {
                continue;
            }

            $quantity = round((float) $item->quantity * $sets, 3);

            /**
             * The slab price at *this* quantity, not the shelf price. A walk-in
             * parent and a four-hundred-set school reading the same list are two
             * different sales, and the difference between them is the trade.
             */
            $unitPrice = $customer
                ? $customer->priceFor($product, $item->variant_id, $quantity)
                : (float) $product->selling_price;

            $available = $onHand[$this->stockKey($item)] ?? 0.0;

            if ($available < $quantity) {
                $shortfalls[] = [
                    'kit_item_id' => $item->id,
                    'product_id' => $product->id,
                    'name' => $item->printedName(),
                    'required' => $quantity,
                    'available' => round($available, 3),
                    'short_by' => round($quantity - $available, 3),
                ];
            }

            $items[] = [
                'product_id' => $product->id,
                'variant_id' => $item->variant_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_percent' => (float) $product->tax_percent,
                // Passthrough, returned to the caller under 'source'.
                '_kit_item_id' => $item->id,
                '_name' => $item->printedName(),
                '_catalogue_name' => $product->name,
                '_sku' => $product->sku,
                '_is_optional' => (bool) $item->is_optional,
                '_available' => round($available, 3),
            ];
        }

        $discountPercent = $this->discountPercent($kit, $buyerIsTheInstitution);

        $priced = $this->pricing->price($items, $discountPercent > 0 ? 'percent' : null, $discountPercent);

        return [
            'lines' => $priced['lines'],
            'header' => $priced['header'],
            'shortfalls' => $shortfalls,
            'discount_percent' => $discountPercent,
        ];
    }

    /**
     * Raise a tender quotation for a school against one of its lists.
     *
     * A school order in this trade almost never starts as an order. It starts
     * weeks earlier as an enquiry the shop answers in writing and then waits on,
     * which is why this produces a core `SalesQuotation` — send, accept, reject
     * and convert already exist behind it, and the shop should not learn a second
     * set of buttons for the same document.
     *
     * @param  array{customer_id?: int|null, warehouse_id: int, sets?: float, valid_until?: string|null, notes?: string|null, item_ids?: list<int>|null, include_optional?: bool}  $options
     */
    public function quote(ClassKit $kit, array $options, ?int $userId = null): SalesQuotation
    {
        $this->assertSellable($kit);

        $customer = $this->buyer($kit, $options['customer_id'] ?? null);
        $sets = (float) ($options['sets'] ?? 1);

        $estimate = $this->estimate(
            kit: $kit,
            customer: $customer,
            sets: $sets,
            warehouseId: $options['warehouse_id'],
            includeOptional: (bool) ($options['include_optional'] ?? false),
            onlyItemIds: $options['item_ids'] ?? null,
            buyerIsTheInstitution: true,
        );

        return DB::transaction(function () use ($kit, $customer, $sets, $options, $estimate, $userId) {
            $header = $estimate['header'];

            $quotation = SalesQuotation::create([
                'quote_number' => SalesQuotation::nextNumber(),
                'customer_id' => $customer->id,
                'warehouse_id' => $options['warehouse_id'],
                'status' => 'draft',
                'quote_date' => now()->toDateString(),
                'valid_until' => $options['valid_until'] ?? null,
                'notes' => $options['notes'] ?? $this->documentNote($kit, $sets),
                'created_by' => $userId,
                // Carried so the enquiry is findable by school, and so that
                // converting it stamps the order — see SchoolQuotationObserver.
                'stn_institution_id' => $kit->institution_id,
                'stn_class_kit_id' => $kit->id,
            ]);

            $this->writeLines($quotation, $estimate['lines']);
            $this->writeTotals($quotation, $header);

            return $quotation;
        });
    }

    /**
     * Raise the school's own supply order from a booklist.
     *
     * A `SalesOrder` on the `institution` channel, created as a draft exactly
     * like one typed on the sales screen — so picking, packing, phased delivery,
     * invoicing and the receivable behind it are all the code that already works.
     * The only things this adds are the three references a general sales order
     * has no reason to carry: which school, which list, and the purchase-order
     * number their accounts office will demand.
     *
     * @param  array{customer_id?: int|null, warehouse_id: int, sets?: float, po_number?: string|null, po_date?: string|null, notes?: string|null, item_ids?: list<int>|null, include_optional?: bool}  $options
     */
    public function order(ClassKit $kit, array $options, ?int $userId = null): SalesOrder
    {
        $this->assertSellable($kit);

        $customer = $this->buyer($kit, $options['customer_id'] ?? null);
        $institution = $kit->institution;
        $sets = (float) ($options['sets'] ?? 1);
        $poNumber = $options['po_number'] ?? null;

        /**
         * Refused rather than warned about. The flag exists because this is the
         * commonest reason an institutional receivable ages past ninety days,
         * and it is knowable now — letting the order through and discovering it
         * in September is precisely the failure the flag was recorded to prevent.
         */
        if ($institution?->requires_po && ! $poNumber) {
            throw new RuntimeException(
                "{$institution->name} will not settle a bill without their purchase-order number. Enter it before raising the order.",
            );
        }

        $estimate = $this->estimate(
            kit: $kit,
            customer: $customer,
            sets: $sets,
            warehouseId: $options['warehouse_id'],
            includeOptional: (bool) ($options['include_optional'] ?? false),
            onlyItemIds: $options['item_ids'] ?? null,
            buyerIsTheInstitution: true,
        );

        return DB::transaction(function () use ($kit, $customer, $sets, $options, $estimate, $poNumber, $userId) {
            $header = $estimate['header'];

            $order = SalesOrder::create([
                'so_number' => SalesOrder::nextNumber(),
                'customer_id' => $customer->id,
                'warehouse_id' => $options['warehouse_id'],
                'status' => 'draft',
                'channel' => 'institution',
                'order_date' => now()->toDateString(),
                'notes' => $options['notes'] ?? $this->documentNote($kit, $sets),
                'created_by' => $userId,
                'stn_institution_id' => $kit->institution_id,
                'stn_class_kit_id' => $kit->id,
                'stn_po_number' => $poNumber,
                'stn_po_date' => $options['po_date'] ?? null,
            ]);

            $this->writeLines($order, $estimate['lines']);
            $this->writeTotals($order, $header);

            return $order;
        });
    }

    /**
     * Copy a booklist into the next session.
     *
     * The single biggest saving in this trade and the least interesting code in
     * it. A school's list changes by two or three items a year; retyping
     * twenty-two lines for each of twelve classes is a week of somebody's April,
     * and it is the reason the lists live under the counter on paper instead of
     * in a system. The copy lands as a draft, because last year's list is a
     * starting point and not a promise.
     */
    public function rollForward(ClassKit $kit, string $academicYear, ?int $userId = null): ClassKit
    {
        if ($academicYear === $kit->academic_year) {
            throw new RuntimeException('Choose a different session to copy this list into.');
        }

        $kit->loadMissing('items');

        return DB::transaction(function () use ($kit, $academicYear, $userId) {
            $copy = ClassKit::create([
                'kit_number' => ClassKit::nextNumber(),
                'institution_id' => $kit->institution_id,
                'academic_year' => $academicYear,
                'class_label' => $kit->class_label,
                'stream' => $kit->stream,
                'title' => $kit->title,
                'status' => 'draft',
                'discount_percent' => $kit->discount_percent,
                'is_mandatory_set' => $kit->is_mandatory_set,
                'expected_students' => $kit->expected_students,
                'notes' => $kit->notes,
                'created_by' => $userId,
            ]);

            foreach ($kit->items as $item) {
                $copy->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                    'is_optional' => $item->is_optional,
                    'remark' => $item->remark,
                    'sort_order' => $item->sort_order,
                ]);
            }

            return $copy;
        });
    }

    /**
     * What the season needs buying in, across every published list.
     *
     * Each kit's line quantity multiplied by the students expected to buy it,
     * netted against stock. This is the number an April purchase order is worked
     * back from, and getting it wrong in either direction is what the trade's
     * whole year turns on — short means turning parents away in the ten weeks
     * that pay for the year, long means a godown of Class 7 books nobody will
     * ever ask for again.
     *
     * Optional lines are counted at full strength, deliberately. A geometry box
     * marked optional on the list is still asked for by some fraction of four
     * hundred parents, and nothing here knows what fraction — so this reports the
     * upper bound and leaves the buyer to discount it, which is the direction a
     * buying list should err in when the alternative is turning people away in
     * June.
     *
     * @return list<array<string, mixed>>
     */
    public function seasonDemand(string $academicYear, ?int $institutionId = null): array
    {
        $kits = ClassKit::query()
            ->with(['items.product:id,sku,name', 'institution:id,name'])
            ->where('academic_year', $academicYear)
            ->where('status', 'published')
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->get();

        $demand = [];

        foreach ($kits as $kit) {
            /**
             * Falls back to the school's roll and then to one set. A published
             * list with no forecast against it is still a list the shop will be
             * asked for, and leaving it out of the demand entirely is how the
             * biggest school in town gets missed.
             */
            $sets = (float) ($kit->expected_students ?: $kit->institution?->student_count ?: 1);

            foreach ($kit->items as $item) {
                if (! $item->product) {
                    continue;
                }

                $key = $item->product_id.':'.($item->variant_id ?? 0);

                $demand[$key] ??= [
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'sku' => $item->product->sku,
                    'name' => $item->product->name,
                    'required' => 0.0,
                    'kits' => [],
                ];

                $demand[$key]['required'] += (float) $item->quantity * $sets;
                $demand[$key]['kits'][] = $kit->label();
            }
        }

        $onHandByProduct = WarehouseStock::query()
            ->whereIn('product_id', array_column($demand, 'product_id'))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $rows = array_map(static function (array $row) use ($onHandByProduct) {
            $available = (float) ($onHandByProduct[$row['product_id']] ?? 0);
            $row['required'] = round($row['required'], 3);
            $row['available'] = round($available, 3);
            $row['to_buy'] = round(max(0, $row['required'] - $available), 3);
            $row['kits'] = array_values(array_unique($row['kits']));

            return $row;
        }, array_values($demand));

        // Biggest gap first: that is the order the buying gets done in.
        usort($rows, static fn (array $a, array $b) => $b['to_buy'] <=> $a['to_buy']);

        return $rows;
    }

    /**
     * The buyer for a kit document.
     *
     * A school's own order bills to the ledger party behind the institution,
     * which is routinely a trust with a different name. An explicit customer wins
     * — a parent buying a set at the counter is not the school.
     */
    private function buyer(ClassKit $kit, ?int $customerId): Customer
    {
        if ($customerId) {
            return Customer::findOrFail($customerId);
        }

        $customer = $kit->institution?->customer;

        if (! $customer) {
            throw new RuntimeException(
                'This school has no billing customer on its account. Link one, or choose who the document is for.',
            );
        }

        return $customer;
    }

    /**
     * The discount on a kit document: the school's concession plus the shop's own
     * set discount, added rather than compounded.
     *
     * Added because that is how both are quoted — "10% school concession, 5% for
     * taking the whole set" is 15% off to everyone who says it, and compounding
     * to 14.5% produces a bill the parent can prove is wrong. Capped at 100 so a
     * mis-keyed pair cannot invert a line.
     */
    private function discountPercent(ClassKit $kit, bool $buyerIsTheInstitution): float
    {
        $institution = (float) ($kit->institution?->discountFor($buyerIsTheInstitution) ?? 0);

        return min(100, round($institution + (float) $kit->discount_percent, 2));
    }

    private function assertSellable(ClassKit $kit): void
    {
        if (! $kit->isSellable()) {
            throw new RuntimeException(
                "Booklist {$kit->kit_number} is {$kit->status}. Publish it before supplying against it.",
            );
        }
    }

    /**
     * On-hand quantity per kit line, in one query.
     *
     * Warehouse-scoped when a warehouse is named, because that is the shelf the
     * document will be served from and the total across a chain says nothing
     * useful to the branch that has none.
     *
     * @param  iterable<ClassKitItem>  $items
     * @return array<string, float>
     */
    private function onHand(iterable $items, ?int $warehouseId): array
    {
        $productIds = [];
        foreach ($items as $item) {
            $productIds[] = $item->product_id;
        }

        if ($productIds === []) {
            return [];
        }

        $rows = WarehouseStock::query()
            ->whereIn('product_id', array_unique($productIds))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('product_id, variant_id, SUM(quantity) as qty')
            ->groupBy('product_id', 'variant_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->product_id.':'.($row->variant_id ?? 0)] = (float) $row->qty;
        }

        return $out;
    }

    private function stockKey(ClassKitItem $item): string
    {
        return $item->product_id.':'.($item->variant_id ?? 0);
    }

    /** "Class 5 · Science — Sunrise English School (2026-27) × 400 sets". */
    private function documentNote(ClassKit $kit, float $sets): string
    {
        $suffix = $sets > 1 ? ' × '.rtrim(rtrim(number_format($sets, 2, '.', ''), '0'), '.').' sets' : '';

        return $kit->label().$suffix;
    }

    /**
     * Write priced lines onto a quotation or an order.
     *
     * Both documents take the same line shape — that is not a coincidence, it is
     * why converting one into the other works — so one writer serves both.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(SalesQuotation|SalesOrder $document, array $lines): void
    {
        foreach ($lines as $line) {
            $document->items()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_percent' => $line['tax_percent'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $header */
    private function writeTotals(SalesQuotation|SalesOrder $document, array $header): void
    {
        $document->update([
            'subtotal' => $header['subtotal'],
            'item_discount_total' => $header['item_discount_total'],
            'discount_type' => $header['discount_type'],
            'discount_value' => $header['discount_value'],
            'discount_amount' => $header['discount_amount'],
            'tax_amount' => $header['tax_amount'],
            'round_off' => $header['round_off'],
            'total' => $header['total'],
        ]);
    }
}
