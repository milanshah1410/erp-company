<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Modules\Industry\Healthcare\Models\LabOrder;
use App\Modules\Industry\Healthcare\Models\LabOrderItem;
use App\Modules\Industry\Healthcare\Models\LabOrderResult;
use App\Modules\Industry\Healthcare\Models\LabTest;
use App\Services\DocumentPricingService;
use RuntimeException;

/**
 * The lab's workflow: register → collect → result → verify → deliver, plus the
 * one moment where the money leaves for the books.
 *
 * Two things this service exists to keep honest.
 *
 * **A report is not the same document as a bill.** Registering an order writes
 * both the billable lines and the empty parameter sheet, and from then on they
 * move independently: the sample can be collected and run whether or not anyone
 * has paid, and a corporate panel is billed weeks after it was reported. So
 * `bill()` is its own step, idempotent through `invoice_id` in the same way
 * every other module here stamps its source rows.
 *
 * **Verifying is what makes a value a result.** Until a qualified person signs
 * it off, what is in the table is a reading. `verify()` is the only path to
 * 'reported', it refuses an incomplete sheet, and it records who signed.
 */
class LabOrderService
{
    public function __construct(
        private readonly DocumentPricingService $pricing,
    ) {}

    /**
     * Write an order's test lines and the empty parameter sheet under them.
     *
     * Replaces whatever was there. Safe because the controller only allows it
     * while the order is still 'registered' and unbilled — once a sample exists,
     * the lines describe something that physically happened and are frozen.
     *
     * @param  array<int, array{lab_test_id: int, unit_price?: float|null, discount_type?: string|null, discount_value?: float|null}>  $lines
     */
    public function syncTests(LabOrder $order, array $lines): void
    {
        $order->results()->delete();
        $order->items()->delete();

        $tests = LabTest::query()
            ->with('components')
            ->whereIn('id', array_column($lines, 'lab_test_id'))
            ->get()
            ->keyBy('id');

        $sort = 0;

        foreach ($lines as $line) {
            /** @var LabTest|null $test */
            $test = $tests->get($line['lab_test_id']);

            if (! $test) {
                continue;
            }

            $item = $order->items()->create([
                'lab_test_id' => $test->id,
                // Snapshotted here, not read through at print time: a test
                // renamed next year must not rewrite a report already issued.
                'test_code' => $test->code,
                'test_name' => $test->name,
                'sample_type' => $test->sample_type,
                'unit_price' => $line['unit_price'] ?? (float) $test->price,
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'] ?? 0,
                'tax_percent' => (float) $test->tax_percent,
                'status' => 'pending',
            ]);

            // A panel bills once and reports many times; a plain test reports
            // itself. Either way the sheet is written now, empty, so the
            // technician has somewhere to type and the order knows what it owes.
            foreach ($test->reportableParameters() as $parameter) {
                $order->results()->create([
                    'lab_order_item_id' => $item->id,
                    'lab_test_id' => $parameter->id,
                    'parameter' => $parameter->name,
                    'unit' => $parameter->unit,
                    'reference_range' => $parameter->reference_range,
                    'ref_low' => $parameter->ref_low,
                    'ref_high' => $parameter->ref_high,
                    'flag' => 'pending',
                    'sort_order' => $sort++,
                ]);
            }
        }

        $this->reprice($order);
    }

    /**
     * Re-derive the header totals from the lines.
     *
     * Every quantity is 1 — a test is ordered once — but it still goes through
     * the shared pricing service so discount-before-GST and the header round-off
     * behave exactly as they do on a counter sale.
     */
    public function reprice(LabOrder $order): LabOrder
    {
        $order->load('items');

        if ($order->items->isEmpty()) {
            $order->fill([
                'subtotal' => 0, 'item_discount_total' => 0, 'discount_amount' => 0,
                'tax_amount' => 0, 'round_off' => 0, 'total' => 0,
            ])->save();

            return $order;
        }

        $priced = $this->pricing->price(
            $order->items->map(fn (LabOrderItem $item) => [
                'quantity' => 1,
                'unit_price' => (float) $item->unit_price,
                'tax_percent' => (float) $item->tax_percent,
                'discount_type' => $item->discount_type,
                'discount_value' => (float) $item->discount_value,
                '_item_id' => $item->id,
            ])->all(),
            $order->discount_type,
            (float) $order->discount_value,
        );

        foreach ($priced['lines'] as $line) {
            $item = $order->items->firstWhere('id', $line['source']['_item_id'] ?? null);
            $item?->forceFill([
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ])->save();
        }

        $header = $priced['header'];

        $order->fill([
            'subtotal' => $header['subtotal'],
            'item_discount_total' => $header['item_discount_total'],
            'discount_amount' => $header['discount_amount'],
            'tax_amount' => $header['tax_amount'],
            'round_off' => $header['round_off'],
            'total' => $header['total'],
        ])->save();

        return $order;
    }

    /**
     * Mark samples drawn.
     *
     * Partial by design: blood is taken at the desk and a urine sample arrives
     * an hour later, and a lab that could only record "everything collected"
     * would have staff waiting or lying. Already-collected lines are skipped
     * rather than refused, so the button is safe to press twice.
     *
     * @param  list<int>|null  $itemIds  Limit to these lines; null collects all pending.
     * @return int Lines actually marked.
     */
    public function collect(LabOrder $order, ?array $itemIds = null): int
    {
        $order->loadMissing('items');

        $pending = $order->items
            ->where('status', 'pending')
            ->when($itemIds !== null, fn ($items) => $items->whereIn('id', $itemIds));

        if ($pending->isEmpty()) {
            return 0;
        }

        foreach ($pending as $item) {
            $item->forceFill(['status' => 'collected', 'collected_at' => now()])->save();
        }

        // The header follows the first sample in, and only moves forward: an
        // order already being processed is not demoted by a late second sample.
        if ($order->status === 'registered') {
            $order->forceFill(['status' => 'collected', 'collected_at' => now()])->save();
        }

        return $pending->count();
    }

    /**
     * Record values against the parameter sheet.
     *
     * The flag is computed here from the snapshot bounds on each row rather than
     * asked of the caller: whether a value is out of range is a fact about the
     * range, not an opinion of whoever is typing, and letting the client send it
     * would make the two disagree the first time someone edited a range.
     *
     * @param  array<int, array{id: int, result_value?: string|null, remarks?: string|null}>  $values
     * @return int Rows updated.
     */
    public function saveResults(LabOrder $order, array $values): int
    {
        $rows = $order->results()->get()->keyBy('id');
        $updated = 0;

        foreach ($values as $entry) {
            /** @var LabOrderResult|null $row */
            $row = $rows->get($entry['id'] ?? null);

            if (! $row) {
                continue;
            }

            $raw = isset($entry['result_value']) ? trim((string) $entry['result_value']) : null;
            $numeric = LabOrderResult::parseNumeric($raw);

            $row->forceFill([
                'result_value' => $raw === '' ? null : $raw,
                'numeric_value' => $numeric,
                'flag' => $this->flagFor($row, $numeric, $raw),
                'remarks' => $entry['remarks'] ?? $row->remarks,
            ])->save();

            $updated++;
        }

        $this->syncItemProgress($order);

        // Entering a value is the lab starting work. Reported/delivered orders
        // stay where they are — a corrected value on a released report is an
        // amendment, not a reason to un-sign it.
        if (in_array($order->status, ['registered', 'collected'], true)) {
            $order->forceFill(['status' => 'processing'])->save();
        }

        return $updated;
    }

    /**
     * Sign the report off and release it.
     *
     * Refuses a sheet with holes in it. A report is read as complete by whoever
     * receives it, so releasing one with three of five values filled says
     * something untrue about the two that are missing.
     */
    public function verify(LabOrder $order, ?int $userId = null): LabOrder
    {
        if ($order->isClosed()) {
            throw new RuntimeException("Lab order {$order->order_number} is already {$order->status}.");
        }

        if (! $order->isFullyResulted()) {
            $missing = $order->results()->where('flag', 'pending')->count();

            throw new RuntimeException(
                "{$missing} parameter(s) still have no result. Complete the sheet before releasing the report.",
            );
        }

        $order->forceFill([
            'status' => 'reported',
            'reported_at' => now(),
            'verified_by' => $userId,
        ])->save();

        $order->items()->whereIn('status', ['pending', 'collected', 'processing'])
            ->update(['status' => 'completed', 'completed_at' => now()]);

        return $order;
    }

    /** Hand the report over. Terminal, and only reachable from a released report. */
    public function deliver(LabOrder $order): LabOrder
    {
        if ($order->status !== 'reported') {
            throw new RuntimeException(
                "Only a released report can be delivered — {$order->order_number} is {$order->status}.",
            );
        }

        $order->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

        return $order;
    }

    /**
     * Bill the order through the ordinary sales spine — a Sales Order on the
     * 'lab' channel plus an Invoice.
     *
     * Idempotent through `invoice_id`, so a double-tap at the desk cannot bill a
     * patient twice. Nothing stock-bearing moves: every line is an is_service
     * product, which is why no warehouse posting happens here even though the
     * sales order names one (it has to).
     */
    public function bill(LabOrder $order, ?int $userId = null): Invoice
    {
        if ($order->invoice_id) {
            return $order->invoice()->firstOrFail();
        }

        if ($order->status === 'cancelled') {
            throw new RuntimeException("Lab order {$order->order_number} is cancelled and cannot be billed.");
        }

        $order->load('items');

        if ($order->items->isEmpty()) {
            throw new RuntimeException("Lab order {$order->order_number} has no tests to bill.");
        }

        $customer = $order->customer_id
            ? Customer::findOrFail($order->customer_id)
            // A walk-in patient is billed to the shared walk-in account, the
            // same record the POS uses — one anonymous customer, not one per
            // person who ever gave a sample.
            : Customer::firstOrCreate(
                ['code' => 'WALKIN'],
                ['name' => 'Walk-in Customer', 'type' => 'retail', 'is_active' => true],
            );

        // Gross of every discount, which is what `subtotal` means on every other
        // sales document. The order's own subtotal is already that, but it is
        // recomputed from the lines so a stale header cannot mis-state the books.
        $subtotal = round($order->items->sum(fn (LabOrderItem $i) => (float) $i->unit_price), 2);

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $customer->id,
            'warehouse_id' => $this->billingWarehouseId($order),
            'status' => 'delivered',
            'channel' => 'lab',
            'order_date' => now()->toDateString(),
            'subtotal' => $subtotal,
            'item_discount_total' => round($order->items->sum(fn (LabOrderItem $i) => (float) $i->discount_amount), 2),
            'discount_type' => $order->discount_type,
            'discount_value' => $order->discount_value,
            'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount,
            'round_off' => $order->round_off,
            'total' => $order->total,
            'notes' => "Lab order {$order->order_number} — {$order->patient_name}",
            'created_by' => $userId,
            'delivered_at' => now(),
        ]);

        foreach ($order->items as $item) {
            $salesOrder->items()->create([
                'product_id' => $this->testProduct($item)->id,
                'variant_id' => null,
                'quantity' => 1,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type,
                'discount_value' => $item->discount_value,
                'discount_amount' => $item->discount_amount,
                'tax_percent' => $item->tax_percent,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ]);
        }

        $invoice = Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'total' => $order->total,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $order->forceFill([
            'sales_order_id' => $salesOrder->id,
            'invoice_id' => $invoice->id,
        ])->save();

        return $invoice;
    }

    /**
     * The catalogue product a test bills as, created on first use.
     *
     * One product per test rather than a single shared "Lab Charges" line: the
     * patient's bill has to name the investigation they paid for, and test-wise
     * revenue then falls out of the ordinary sales reports instead of needing a
     * report of its own. Flagged is_service so nothing tries to stock it.
     */
    public function testProduct(LabOrderItem $item): Product
    {
        $test = $item->test;

        if ($test?->product_id && ($product = Product::find($test->product_id))) {
            return $product;
        }

        $product = Product::firstOrCreate(
            ['sku' => 'LAB-'.$item->test_code],
            [
                'name' => $item->test_name,
                'description' => 'Diagnostic investigation billed from the lab.',
                'is_service' => true,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => (float) $item->unit_price,
                'tax_percent' => (float) $item->tax_percent,
            ],
        );

        $test?->forceFill(['product_id' => $product->id])->save();

        return $product;
    }

    /**
     * A sales order needs a warehouse even though a lab bill moves no goods.
     * Falls back to any warehouse rather than failing the bill.
     */
    private function billingWarehouseId(LabOrder $order): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException("Create a warehouse before billing lab order {$order->order_number}.");
        }

        return (int) $id;
    }

    /**
     * The flag for one entered value, judged against the range the row was
     * created with rather than the master's current one.
     */
    private function flagFor(LabOrderResult $row, ?float $numeric, ?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return 'pending';
        }

        $low = $row->ref_low !== null ? (float) $row->ref_low : null;
        $high = $row->ref_high !== null ? (float) $row->ref_high : null;

        if ($low === null && $high === null) {
            // Qualitative test with no bounds — nothing to judge it against, and
            // colouring it red would train people to ignore the colour.
            return 'normal';
        }

        if ($numeric === null) {
            return 'abnormal';
        }

        return match (true) {
            $low !== null && $numeric < $low => 'low',
            $high !== null && $numeric > $high => 'high',
            default => 'normal',
        };
    }

    /** A test line is complete once every parameter under it has a value. */
    private function syncItemProgress(LabOrder $order): void
    {
        $order->load(['items', 'results']);

        $byItem = $order->results->groupBy('lab_order_item_id');

        foreach ($order->items as $item) {
            $rows = $byItem->get($item->id, collect());
            $done = $rows->isNotEmpty() && $rows->every(fn (LabOrderResult $r) => $r->flag !== 'pending');

            $status = match (true) {
                $done => 'completed',
                $rows->contains(fn (LabOrderResult $r) => $r->flag !== 'pending') => 'processing',
                default => $item->status,
            };

            if ($status !== $item->status) {
                $item->forceFill([
                    'status' => $status,
                    'completed_at' => $status === 'completed' ? now() : null,
                ])->save();
            }
        }
    }
}
