<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ServiceJob;
use App\Models\Setting;
use App\Models\Warehouse;
use RuntimeException;

/**
 * Pricing, parts issue and billing for a service job.
 *
 * The job document itself is only a worksheet. When it is billed, the numbers
 * leave through the ordinary sales spine — a Sales Order on the 'service'
 * channel plus an Invoice — so receivables, GST returns, payments, the customer
 * ledger and every sales report see a service exactly as they see a counter
 * sale. Nothing downstream has to learn that services exist.
 */
class ServiceJobBillingService
{
    public function __construct(
        private readonly DocumentPricingService $pricing,
        private readonly StockService $stock,
        private readonly BrokerCommissionService $brokerCommissions,
    ) {}

    /**
     * Re-price a job from its part lines plus the labour charge, and write the
     * totals back onto the header.
     *
     * Labour is priced as one more line so it discounts and taxes on the same
     * rules as parts — a job-level discount that skipped labour would be a
     * quietly different document from every other one in the system.
     */
    public function reprice(ServiceJob $job): ServiceJob
    {
        $job->loadMissing('items');

        $lines = $job->items->map(fn ($item) => [
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'tax_percent' => (float) $item->tax_percent,
            'discount_type' => $item->discount_type,
            'discount_value' => (float) $item->discount_value,
            '_item_id' => $item->id,
        ])->all();

        $labour = (float) $job->labour_charge;
        if ($labour > 0) {
            $lines[] = [
                'quantity' => 1,
                'unit_price' => $labour,
                'tax_percent' => (float) $job->labour_tax_percent,
                'discount_type' => null,
                'discount_value' => 0,
                '_item_id' => null,
            ];
        }

        if ($lines === []) {
            $job->fill([
                'parts_total' => 0, 'discount_amount' => 0,
                'tax_amount' => 0, 'round_off' => 0, 'total' => 0,
            ])->save();

            return $job;
        }

        $priced = $this->pricing->price($lines, $job->discount_type, (float) $job->discount_value);

        // Write each part line's share back, so the job's own lines and the
        // invoice it eventually raises can never disagree. `line_total` is net
        // of this line's proportional share of the job-level discount, which is
        // the same convention every other document here uses.
        $partsTotal = 0.0;
        foreach ($priced['lines'] as $line) {
            $itemId = $line['source']['_item_id'] ?? null;
            if (! $itemId) {
                continue;
            }

            $item = $job->items->firstWhere('id', $itemId);
            if (! $item) {
                continue;
            }

            $item->forceFill([
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ])->save();

            $partsTotal += (float) $line['line_total'];
        }

        $header = $priced['header'];

        $job->fill([
            'parts_total' => round($partsTotal, 2),
            'discount_amount' => $header['discount_amount'],
            'tax_amount' => $header['tax_amount'],
            'round_off' => $header['round_off'],
            'total' => $header['total'],
        ])->save();

        return $job;
    }

    /**
     * Issue the parts a technician has fitted, moving them out of the job's
     * warehouse.
     *
     * Only stocked lines move: a labour line is a product too, and posting it
     * would drive a phantom negative balance. Already-issued lines are skipped
     * rather than refused, so "issue everything fitted so far" stays a safe
     * button to press twice.
     *
     * @param  list<int>|null  $itemIds  Limit to these lines; null issues all pending.
     * @return int Number of lines actually issued.
     */
    public function issueParts(ServiceJob $job, ?array $itemIds = null, ?int $userId = null): int
    {
        $job->loadMissing('items.product');

        $pending = $job->items
            ->where('is_issued', false)
            ->when($itemIds !== null, fn ($items) => $items->whereIn('id', $itemIds))
            ->filter(fn ($item) => ! $item->product?->is_service);

        if ($pending->isEmpty()) {
            return 0;
        }

        if (! $job->warehouse_id) {
            throw new RuntimeException('Set the job\'s warehouse before issuing parts.');
        }

        foreach ($pending as $item) {
            $this->stock->outward(
                warehouseId: $job->warehouse_id,
                productId: $item->product_id,
                variantId: $item->variant_id,
                quantity: (float) $item->quantity,
                type: 'service_issue',
                referenceType: ServiceJob::class,
                referenceId: $job->id,
                referenceNumber: $job->job_number,
                userId: $userId,
            );

            $item->forceFill(['is_issued' => true])->save();
        }

        return $pending->count();
    }

    /**
     * Bill a completed job: raise the Sales Order + Invoice that carry its value
     * into the books.
     *
     * Idempotent — a job that already has an invoice returns it untouched, so a
     * double-click at the counter cannot bill a repair twice.
     */
    public function bill(ServiceJob $job, ?int $userId = null): ?Invoice
    {
        if ($job->invoice_id) {
            return $job->invoice()->first();
        }

        if (! $job->isBillable()) {
            return null;
        }

        $job->loadMissing('items.product');

        // Parts have already left stock via issueParts(); the sales order exists
        // to carry the money, not to move goods a second time. Anything the
        // technician never issued still bills — it was fitted, the flag only
        // records whether *this* module posted the movement.
        // Gross of every discount, matching what `subtotal` means on every other
        // sales document — the job's `parts_total` is already net, so it cannot
        // stand in here.
        $subtotal = round(
            $job->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price)
                + (float) $job->labour_charge,
            2,
        );
        $itemDiscountTotal = round($job->items->sum(fn ($i) => (float) $i->discount_amount), 2);

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $job->customer_id,
            'warehouse_id' => $job->warehouse_id ?? $this->fallbackWarehouseId($job),
            'status' => 'delivered',
            'channel' => 'service',
            'order_date' => now()->toDateString(),
            'subtotal' => $subtotal,
            'item_discount_total' => $itemDiscountTotal,
            'discount_type' => $job->discount_type,
            'discount_value' => $job->discount_value,
            'discount_amount' => $job->discount_amount,
            'tax_amount' => $job->tax_amount,
            'round_off' => $job->round_off,
            'total' => $job->total,
            'notes' => "Service job {$job->job_number} — {$job->item_name}",
            'created_by' => $userId,
            'delivered_at' => now(),
        ]);

        foreach ($job->items as $item) {
            $salesOrder->items()->create([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type,
                'discount_value' => $item->discount_value,
                'discount_amount' => $item->discount_amount,
                'tax_percent' => $item->tax_percent,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ]);
        }

        if ((float) $job->labour_charge > 0) {
            $labourProduct = $this->labourProduct();
            $salesOrder->items()->create([
                'product_id' => $labourProduct->id,
                'variant_id' => null,
                'quantity' => 1,
                'unit_price' => $job->labour_charge,
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_percent' => $job->labour_tax_percent,
                'tax_amount' => round((float) $job->labour_charge * (float) $job->labour_tax_percent / 100, 2),
                'line_total' => round(
                    (float) $job->labour_charge * (1 + (float) $job->labour_tax_percent / 100),
                    2,
                ),
            ]);
        }

        $invoice = Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $job->customer_id,
            'invoice_date' => now()->toDateString(),
            'total' => $job->total,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->brokerCommissions->accrueForInvoice($invoice);

        $job->forceFill([
            'sales_order_id' => $salesOrder->id,
            'invoice_id' => $invoice->id,
        ])->save();

        return $invoice;
    }

    /**
     * The catalogue product labour is billed under, created on first use.
     *
     * A service invoice needs a line, a line needs a product, and asking every
     * workspace to hand-create one before its first repair is a worse trade than
     * seeding one the moment it is needed. The SAC code is left blank — 9987 is
     * the usual one for maintenance, but guessing a tax code for someone is not
     * this service's business.
     */
    private function labourProduct(): Product
    {
        $sku = (string) Setting::get('service_labour_sku', 'SERVICE-LABOUR');

        return Product::firstOrCreate(
            ['sku' => $sku],
            [
                'name' => 'Service Labour',
                'description' => 'Labour and visit charges billed on service jobs.',
                'is_service' => true,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => 0,
                'tax_percent' => 0,
            ],
        );
    }

    /**
     * A sales order needs a warehouse even when the job never used one (pure
     * labour, on-site visit). Falls back to any warehouse rather than failing
     * the bill — nothing moves through it.
     */
    private function fallbackWarehouseId(ServiceJob $job): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException("Create a warehouse before billing service job {$job->job_number}.");
        }

        return (int) $id;
    }
}
