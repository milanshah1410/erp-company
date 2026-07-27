<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoodsReceiptRequest;
use App\Http\Resources\ShipmentExpenseResource;
use App\Http\Resources\ShipmentResource;
use App\Models\DispatchItem;
use App\Models\DispatchPlan;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Models\SalesItem;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\ShipmentExpense;
use App\Support\TradeCatalogue;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Where the Import & Export Logistics module meets the rest of the ERP.
 *
 * The shipment header carries the trade story; these actions carry the ERP
 * documents on either end of it, so the two workflows in the SRS are one chain
 * rather than two ledgers kept in step by hand:
 *
 *   Import  Purchase Order → Shipment → Supplier Invoice → (customs) → Warehouse Receipt → Stock
 *   Export  Sales Order    → Shipment → Customer Invoice → (customs) → Dispatch
 *
 * Nothing here re-implements the documents it creates. The warehouse receipt is
 * posted through GoodsReceiptController so batch / serial / put-away rules and
 * the stock ledger behave exactly as a hand-keyed GRN; the dispatch is an
 * ordinary draft DispatchPlan the transport desk then works.
 *
 * Currency: shipment money is in the shipment's foreign `currency`, while
 * purchase / sales documents are in the tenant's base currency. A linked
 * invoice's total is therefore only copied onto `invoice_value` when the two
 * agree; otherwise the number the desk reads off the commercial invoice stands
 * and the response says so in `notes`.
 */
class ShipmentIntegrationController extends Controller
{
    /**
     * Import: raise a draft shipment from an approved purchase order, prefilled
     * from the order and the overseas supplier's trade defaults.
     */
    public function fromPurchaseOrder(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'shipment_date' => ['nullable', 'date'],
            'mode' => ['nullable', Rule::in(['sea', 'air', 'road', 'rail', 'courier'])],
            'seed_documents' => ['boolean'],
        ]);

        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::with(['items.product', 'supplier'])->findOrFail($data['purchase_order_id']);

        if (in_array($po->status, ['draft', 'cancelled', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['A shipment can only be raised against a live purchase order.'],
            ]);
        }

        $supplier = $po->supplier;
        $notes = [];
        if ($supplier && ! $supplier->is_overseas) {
            $notes[] = "{$supplier->name} is not flagged as an overseas supplier — check the party before filing customs.";
        }

        $shipment = DB::transaction(function () use ($request, $data, $po, $supplier) {
            $shipment = Shipment::create([
                'shipment_number' => Shipment::nextNumber('import'),
                'direction' => 'import',
                'status' => 'draft',
                'shipment_date' => $data['shipment_date'] ?? now()->toDateString(),
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'warehouse_id' => $po->warehouse_id,
                'mode' => $data['mode'] ?? 'sea',
                'incoterm' => $this->incotermFor($supplier?->incoterm, 'FOB'),
                'origin_country_id' => $supplier?->country_id,
                'port_of_loading_id' => $supplier?->port_id,
                'eta' => $po->expected_date,
                'goods_description' => $this->describeItems($po->items),
                'currency' => $supplier?->currency_code ?: Setting::currencyCode(),
                'exchange_rate' => 1,
                // The order value is base currency; it is the landed-cost input,
                // not the foreign invoice value, which comes off the supplier's
                // commercial invoice when it is linked.
                'goods_cost' => (float) $po->total,
                'customs_entry_type' => 'bill_of_entry',
                'created_by' => $request->user()?->id,
            ]);

            if ($request->boolean('seed_documents', true)) {
                $this->seedDocuments($shipment);
                // The order itself is already in hand, so record it as received
                // rather than leaving it on the chase list.
                $shipment->documents()->create([
                    'doc_type' => 'purchase_order',
                    'doc_number' => $po->po_number,
                    'doc_date' => $po->order_date,
                    'status' => 'received',
                    'is_mandatory' => false,
                ]);
            }

            $shipment->logEvent('other', [
                'description' => "Import shipment raised from purchase order {$po->po_number}",
                'created_by' => $request->user()?->id,
            ]);

            return $shipment;
        });

        return (new ShipmentResource($shipment->load($this->relations())))
            ->additional(['notes' => $notes])
            ->response()->setStatusCode(201);
    }

    /**
     * Export: raise a draft shipment from a sales order, prefilled from the
     * order and the overseas customer's trade defaults.
     */
    public function fromSalesOrder(Request $request)
    {
        $data = $request->validate([
            'sales_order_id' => ['required', 'exists:sales_orders,id'],
            'shipment_date' => ['nullable', 'date'],
            'mode' => ['nullable', Rule::in(['sea', 'air', 'road', 'rail', 'courier'])],
            'seed_documents' => ['boolean'],
        ]);

        /** @var SalesOrder $so */
        $so = SalesOrder::with(['items.product', 'customer'])->findOrFail($data['sales_order_id']);

        if (in_array($so->status, ['cancelled', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'sales_order_id' => ['A shipment cannot be raised against a cancelled sales order.'],
            ]);
        }

        $customer = $so->customer;
        $base = Setting::currencyCode();
        $currency = $customer?->currency_code ?: $base;

        $notes = [];
        if ($customer && ! $customer->is_overseas) {
            $notes[] = "{$customer->name} is not flagged as an overseas customer — check the party before filing the shipping bill.";
        }
        if ($currency !== $base) {
            $notes[] = "Order value is in {$base} but the shipment bills in {$currency}; enter the invoice value from the export invoice.";
        }

        $shipment = DB::transaction(function () use ($request, $data, $so, $customer, $currency, $base) {
            $shipment = Shipment::create([
                'shipment_number' => Shipment::nextNumber('export'),
                'direction' => 'export',
                'status' => 'draft',
                'shipment_date' => $data['shipment_date'] ?? now()->toDateString(),
                'customer_id' => $so->customer_id,
                'sales_order_id' => $so->id,
                'warehouse_id' => $so->warehouse_id,
                'mode' => $data['mode'] ?? 'sea',
                'incoterm' => $this->incotermFor($customer?->incoterm, 'CIF'),
                'destination_country_id' => $customer?->country_id,
                'port_of_discharge_id' => $customer?->port_id,
                'goods_description' => $this->describeItems($so->items),
                'currency' => $currency,
                'exchange_rate' => 1,
                // Revenue only carries over when both sides are in the same
                // currency; COGS is estimated from the products' cost price so
                // the Shipment Profit board has something to work with.
                'invoice_value' => $currency === $base ? (float) $so->total : 0,
                'goods_cost' => $this->estimatedCogs($so),
                'customs_entry_type' => 'shipping_bill',
                'created_by' => $request->user()?->id,
            ]);

            if ($request->boolean('seed_documents', true)) {
                $this->seedDocuments($shipment);
            }

            $shipment->logEvent('other', [
                'description' => "Export shipment raised from sales order {$so->so_number}",
                'created_by' => $request->user()?->id,
            ]);

            return $shipment;
        });

        return (new ShipmentResource($shipment->load($this->relations())))
            ->additional(['notes' => $notes])
            ->response()->setStatusCode(201);
    }

    /**
     * Import: attach the vendor bill booked for this consignment (or detach it
     * by passing null). The bill's number and date always carry over; its total
     * becomes the goods value, and the foreign invoice value too when the
     * currencies agree.
     */
    public function supplierInvoice(Request $request, Shipment $shipment)
    {
        $this->assertDirection($shipment, 'import');

        $data = $request->validate([
            'purchase_invoice_id' => ['present', 'nullable', 'exists:purchase_invoices,id'],
        ]);

        if ($data['purchase_invoice_id'] === null) {
            $shipment->update(['purchase_invoice_id' => null]);

            return $this->linkResponse($shipment, ['Supplier invoice unlinked.']);
        }

        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::findOrFail($data['purchase_invoice_id']);

        if ($shipment->supplier_id && $invoice->supplier_id !== $shipment->supplier_id) {
            throw ValidationException::withMessages([
                'purchase_invoice_id' => ['That invoice belongs to a different supplier.'],
            ]);
        }
        if ($shipment->purchase_order_id && $invoice->purchase_order_id
            && $invoice->purchase_order_id !== $shipment->purchase_order_id) {
            throw ValidationException::withMessages([
                'purchase_invoice_id' => ['That invoice belongs to a different purchase order.'],
            ]);
        }

        $notes = [];
        $attributes = [
            'purchase_invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date,
            'goods_cost' => (float) $invoice->total,
        ];

        if ($shipment->currency === Setting::currencyCode()) {
            $attributes['invoice_value'] = (float) $invoice->total;
        } else {
            $notes[] = "Invoice total is in ".Setting::currencyCode()." while the shipment bills in {$shipment->currency}; the foreign invoice value was left as entered.";
        }
        if ($invoice->due_date && ! $shipment->payment_due_date) {
            $attributes['payment_due_date'] = $invoice->due_date;
        }

        $shipment->update($attributes);
        $shipment->logEvent('document_received', [
            'description' => "Supplier invoice {$invoice->invoice_number} linked",
            'created_by' => $request->user()?->id,
        ]);
        $this->markDocument($shipment, 'commercial_invoice', $invoice->invoice_number, $invoice->invoice_date);

        return $this->linkResponse($shipment, $notes);
    }

    /**
     * Export: attach the customer invoice raised for this consignment (or
     * detach it by passing null).
     */
    public function customerInvoice(Request $request, Shipment $shipment)
    {
        $this->assertDirection($shipment, 'export');

        $data = $request->validate([
            'invoice_id' => ['present', 'nullable', 'exists:invoices,id'],
        ]);

        if ($data['invoice_id'] === null) {
            $shipment->update(['invoice_id' => null]);

            return $this->linkResponse($shipment, ['Customer invoice unlinked.']);
        }

        /** @var Invoice $invoice */
        $invoice = Invoice::findOrFail($data['invoice_id']);

        if ($shipment->customer_id && $invoice->customer_id !== $shipment->customer_id) {
            throw ValidationException::withMessages([
                'invoice_id' => ['That invoice belongs to a different customer.'],
            ]);
        }
        if ($shipment->sales_order_id && $invoice->sales_order_id
            && $invoice->sales_order_id !== $shipment->sales_order_id) {
            throw ValidationException::withMessages([
                'invoice_id' => ['That invoice belongs to a different sales order.'],
            ]);
        }

        $notes = [];
        $attributes = [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date,
        ];

        if ($shipment->currency === Setting::currencyCode()) {
            $attributes['invoice_value'] = (float) $invoice->total;
        } else {
            $notes[] = "Invoice total is in ".Setting::currencyCode()." while the shipment bills in {$shipment->currency}; the foreign invoice value was left as entered.";
        }
        if ($invoice->due_date && ! $shipment->payment_due_date) {
            $attributes['payment_due_date'] = $invoice->due_date;
        }

        $shipment->update($attributes);
        $shipment->logEvent('document_received', [
            'description' => "Customer invoice {$invoice->invoice_number} linked",
            'created_by' => $request->user()?->id,
        ]);
        $this->markDocument($shipment, 'commercial_invoice', $invoice->invoice_number, $invoice->invoice_date);

        return $this->linkResponse($shipment, $notes);
    }

    /**
     * Import: take the arrived cargo into stock.
     *
     * Posts a goods receipt against the shipment's purchase order — by default
     * every outstanding line in full, or the lines given in `items` (same shape
     * as POST /goods-receipts, so batch / expiry / serial / put-away all work).
     * Landed cost defaults to what the shipment actually cost to land: freight
     * and insurance converted to base currency, plus customs duty.
     */
    public function warehouseReceipt(Request $request, Shipment $shipment)
    {
        $this->assertDirection($shipment, 'import');

        if ($shipment->goods_receipt_id) {
            throw ValidationException::withMessages([
                'shipment' => ['This shipment has already been received into stock.'],
            ]);
        }
        if (! $shipment->purchase_order_id) {
            throw ValidationException::withMessages([
                'shipment' => ['Link a purchase order to the shipment before receiving it into stock.'],
            ]);
        }
        if (! in_array($shipment->status, ['arrived', 'customs', 'cleared', 'delivered'], true)) {
            throw ValidationException::withMessages([
                'shipment' => ['Cargo can only be received once the shipment has arrived (status is '.$shipment->status.').'],
            ]);
        }

        $data = $request->validate([
            'received_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.purchase_item_id' => ['required_with:items', 'exists:purchase_items,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.serials' => ['nullable', 'array'],
            'items.*.serials.*' => ['string', 'max:100'],
            // Override the shipment-derived apportionment if the desk knows better.
            'landed_cost' => ['nullable', 'array'],
            'landed_cost.freight_amount' => ['nullable', 'numeric', 'min:0'],
            'landed_cost.duty_amount' => ['nullable', 'numeric', 'min:0'],
            'landed_cost.other_amount' => ['nullable', 'numeric', 'min:0'],
            'landed_cost.allocation_method' => ['nullable', Rule::in(['value', 'quantity'])],
        ]);

        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::with('items')->findOrFail($shipment->purchase_order_id);

        $notes = [];
        if ($shipment->warehouse_id && $po->warehouse_id !== $shipment->warehouse_id) {
            $notes[] = 'Stock was received into the purchase order\'s warehouse, not the shipment\'s.';
        }

        // Default to everything still outstanding on the order.
        $items = $data['items'] ?? $po->items
            ->filter(fn ($item) => $item->outstandingQuantity() > 0)
            ->map(fn ($item) => [
                'purchase_item_id' => $item->id,
                'quantity' => $item->outstandingQuantity(),
            ])
            ->values()
            ->all();

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Nothing is outstanding on '.$po->po_number.' — every line has already been received.'],
            ]);
        }

        $payload = [
            'purchase_order_id' => $po->id,
            'received_date' => $data['received_date'] ?? $shipment->ata?->toDateString() ?? now()->toDateString(),
            'notes' => $data['notes'] ?? "Received against shipment {$shipment->shipment_number}",
            'items' => $items,
            'landed_cost' => $data['landed_cost'] ?? [
                'freight_amount' => $shipment->freightInsuranceBase(),
                'duty_amount' => (float) $shipment->duty_total,
                'other_amount' => 0,
                'allocation_method' => 'value',
            ],
        ];

        // Post through the ordinary GRN path so tracking rules, put-away, landed
        // cost and the stock ledger all behave identically to a hand-keyed one.
        $grnRequest = GoodsReceiptRequest::create(
            '/api/goods-receipts', 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json'],
        );
        $grnRequest->setContainer(app())->setRedirector(app(Redirector::class));
        $grnRequest->setUserResolver(fn () => $request->user());
        $grnRequest->validateResolved();

        $response = app(GoodsReceiptController::class)->store($grnRequest);
        $grnId = $response->getData(true)['data']['id'] ?? null;

        if ($grnId) {
            $grn = GoodsReceipt::find($grnId);
            $shipment->update(['goods_receipt_id' => $grnId]);
            $shipment->logEvent('warehouse_receipt', [
                'description' => 'Goods receipt '.($grn?->grn_number ?? $grnId).' posted to stock',
                'location' => $grn?->warehouse?->name,
                'created_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'message' => 'Warehouse receipt posted.',
            'goods_receipt' => $response->getData(true)['data'] ?? null,
            'shipment' => new ShipmentResource($shipment->fresh()->load($this->relations())),
            'notes' => $notes,
        ], 201);
    }

    /**
     * Export: raise the outbound dispatch for this consignment — a draft
     * dispatch plan carrying one consignment for the overseas customer, which
     * the transport desk then schedules and marks delivered as usual.
     */
    public function dispatch(Request $request, Shipment $shipment)
    {
        $this->assertDirection($shipment, 'export');

        if ($shipment->dispatch_plan_id) {
            throw ValidationException::withMessages([
                'shipment' => ['This shipment has already been dispatched.'],
            ]);
        }
        if (! $shipment->customer_id) {
            throw ValidationException::withMessages([
                'shipment' => ['Set the overseas customer on the shipment before dispatching it.'],
            ]);
        }

        $data = $request->validate([
            'dispatch_date' => ['nullable', 'date'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'exists:drivers,id'],
            'route_id' => ['nullable', 'exists:delivery_routes,id'],
            'delivery_address' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        $shipment->loadMissing(['portOfLoading', 'containers']);

        $plan = DB::transaction(function () use ($request, $data, $shipment) {
            $plan = DispatchPlan::create([
                'dispatch_number' => DispatchPlan::nextNumber(),
                'dispatch_date' => $data['dispatch_date'] ?? $shipment->etd?->toDateString() ?? now()->toDateString(),
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'driver_id' => $data['driver_id'] ?? null,
                'route_id' => $data['route_id'] ?? null,
                'status' => 'draft',
                'remarks' => $data['remarks'] ?? "Export shipment {$shipment->shipment_number}",
                'created_by' => $request->user()?->id,
            ]);

            DispatchItem::create([
                'dispatch_plan_id' => $plan->id,
                'customer_id' => $shipment->customer_id,
                'sales_order_id' => $shipment->sales_order_id,
                // Export cargo goes to the port of loading unless the desk says
                // otherwise — that is the leg a transporter actually drives.
                'delivery_address' => $data['delivery_address']
                    ?? $shipment->final_place
                    ?? $shipment->portOfLoading?->name,
                'packages' => max(1, (int) $shipment->packages),
                'weight_kg' => $shipment->gross_weight_kg,
                'sequence' => 1,
                'delivery_status' => 'pending',
            ]);

            $shipment->update(['dispatch_plan_id' => $plan->id]);
            $shipment->logEvent('dispatched', [
                'description' => "Dispatch {$plan->dispatch_number} raised",
                'location' => $shipment->portOfLoading?->name,
                'created_by' => $request->user()?->id,
            ]);

            return $plan;
        });

        return response()->json([
            'message' => 'Dispatch raised.',
            'dispatch_plan' => [
                'id' => $plan->id,
                'dispatch_number' => $plan->dispatch_number,
                'dispatch_date' => $plan->dispatch_date?->toDateString(),
                'status' => $plan->status,
            ],
            'shipment' => new ShipmentResource($shipment->fresh()->load($this->relations())),
        ], 201);
    }

    /**
     * Container: turn a box's overdue days into a detention / demurrage cost.
     *
     * The Container Status board already counts the days past `free_days`; this
     * prices them and books the charge against the shipment, attributed to the
     * box, so it lands in Logistics Expenses and the Shipment Profit board
     * before the carrier's invoice does. Billed by the carrier on the shipment
     * unless another vendor is named.
     *
     * Re-running it re-prices the same charge rather than stacking duplicates,
     * so the desk can refresh a still-accruing box daily. Once the charge has
     * been paid it is left alone and a fresh line is opened for the new period.
     */
    public function detentionCharge(Request $request, ShipmentContainer $shipmentContainer)
    {
        $shipment = $shipmentContainer->shipment;
        if (! $shipment) {
            throw ValidationException::withMessages([
                'container' => ['This container is not attached to a shipment.'],
            ]);
        }

        $data = $request->validate([
            'expense_type' => ['nullable', Rule::in(['detention', 'demurrage'])],
            'rate_per_day' => ['required', 'numeric', 'min:0.01'],
            // Defaults to the days the board is already showing; override to
            // charge an agreed number instead of the calculated one.
            'days' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'vendor_type' => ['nullable', Rule::in(array_keys(ShipmentExpense::VENDOR_MODELS))],
            'vendor_id' => ['nullable', 'integer'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'is_billable' => ['boolean'],
            'remarks' => ['nullable', 'string'],
        ]);

        $days = $data['days'] ?? $shipmentContainer->detentionDays();
        if ($days < 1) {
            throw ValidationException::withMessages([
                'days' => $shipmentContainer->detention_free_until
                    ? ["Container {$shipmentContainer->container_number} is still inside its free days (until {$shipmentContainer->detention_free_until->toDateString()}). Pass `days` to charge anyway."]
                    : ["Container {$shipmentContainer->container_number} has no free-days window set, so overdue days cannot be calculated. Pass `days` to charge anyway."],
            ]);
        }

        $type = $data['expense_type'] ?? 'detention';
        $amount = round((float) $data['rate_per_day'] * $days, 2);

        // Detention is normally billed where the box sits, in base currency;
        // when it is billed in the shipment's currency the shipment's rate
        // converts it, so both sides of the profit board agree.
        $base = Setting::currencyCode();
        $currency = strtoupper($data['currency'] ?? $base);
        $exchangeRate = $data['exchange_rate']
            ?? ($currency === $shipment->currency ? (float) $shipment->exchange_rate : 1);

        // The carrier owns the box, so it is the default payee.
        [$vendorType, $vendorId] = $this->carrierVendor($shipment);

        $attributes = [
            'shipment_id' => $shipment->id,
            'shipment_container_id' => $shipmentContainer->id,
            'expense_type' => $type,
            'expense_date' => $data['expense_date'] ?? now()->toDateString(),
            'vendor_type' => $data['vendor_type'] ?? $vendorType,
            'vendor_id' => $data['vendor_id'] ?? $vendorId,
            'vendor_name' => $data['vendor_name'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'amount' => $amount,
            'due_date' => $data['due_date'] ?? null,
            'is_billable' => $request->boolean('is_billable'),
            'remarks' => $data['remarks']
                ?? "{$days} day(s) × ".number_format((float) $data['rate_per_day'], 2)." on container {$shipmentContainer->container_number}",
        ];

        // Refresh the open charge for this box rather than stacking a second one.
        $existing = ShipmentExpense::query()
            ->where('shipment_container_id', $shipmentContainer->id)
            ->where('expense_type', $type)
            ->where('payment_status', 'unpaid')
            ->orderByDesc('id')
            ->first();

        $expense = DB::transaction(function () use ($existing, $attributes, $request, $shipment, $shipmentContainer, $days, $type) {
            if ($existing) {
                $existing->update($attributes);
                $expense = $existing->fresh();
            } else {
                $expense = ShipmentExpense::create($attributes + ['created_by' => $request->user()?->id]);
            }

            $shipment->logEvent('other', [
                'description' => ucfirst($type)." charged on container {$shipmentContainer->container_number}: "
                    ."{$days} day(s), {$expense->currency} ".number_format((float) $expense->amount, 2),
                'created_by' => $request->user()?->id,
            ]);

            return $expense;
        });

        return (new ShipmentExpenseResource($expense->load(['shipment:id,shipment_number,direction', 'container'])))
            ->additional([
                'message' => $existing ? 'Detention charge re-priced.' : 'Detention charge posted.',
                'days_charged' => $days,
                'created' => ! $existing,
            ])
            ->response()->setStatusCode($existing ? 200 : 201);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The carrier holding the box — a shipping line by sea, an airline by air.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function carrierVendor(Shipment $shipment): array
    {
        if ($shipment->mode === 'air' && $shipment->airline_id) {
            return ['airline', $shipment->airline_id];
        }
        if ($shipment->shipping_line_id) {
            return ['shipping_line', $shipment->shipping_line_id];
        }

        return [null, null];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'supplier', 'customer', 'purchaseOrder', 'salesOrder', 'warehouse',
            'purchaseInvoice', 'goodsReceipt', 'invoice', 'dispatchPlan',
            'shippingLine', 'airline', 'freightForwarder', 'chaAgent', 'transporter',
            'originCountry', 'destinationCountry', 'portOfLoading', 'portOfDischarge',
        ];
    }

    private function assertDirection(Shipment $shipment, string $direction): void
    {
        if ($shipment->direction !== $direction) {
            throw ValidationException::withMessages([
                'shipment' => ["This action applies to {$direction} shipments only."],
            ]);
        }
    }

    /** @param  list<string>  $notes */
    private function linkResponse(Shipment $shipment, array $notes)
    {
        return (new ShipmentResource($shipment->fresh()->load($this->relations())))
            ->additional(['notes' => $notes]);
    }

    /** Seed the leg's mandatory document checklist, as a hand-created shipment does. */
    private function seedDocuments(Shipment $shipment): void
    {
        foreach (TradeCatalogue::mandatoryDocumentsFor($shipment->direction) as $docType) {
            $shipment->documents()->create([
                'doc_type' => $docType,
                'status' => 'pending',
                'is_mandatory' => true,
                'due_date' => $shipment->etd ?? $shipment->eta,
            ]);
        }
    }

    /**
     * Tick a checklist row off when the document behind it arrives. Only touches
     * a row still being chased, so a verified document is never downgraded.
     */
    private function markDocument(Shipment $shipment, string $docType, ?string $number, string|CarbonInterface|null $date): void
    {
        $shipment->documents()
            ->where('doc_type', $docType)
            ->whereIn('status', ['pending', 'rejected'])
            ->orderBy('id')
            ->first()
            ?->update([
                'doc_number' => $number,
                'doc_date' => $date,
                'status' => 'received',
            ]);
    }

    /** The party's default Incoterm, if it is one the shipment enum accepts. */
    private function incotermFor(?string $incoterm, string $fallback): string
    {
        $allowed = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CIP', 'DAP', 'DDP'];

        return in_array(strtoupper((string) $incoterm), $allowed, true)
            ? strtoupper((string) $incoterm)
            : $fallback;
    }

    /**
     * "Cotton Shirts, Denim Jeans +3 more" — a readable cargo description.
     *
     * @param  Collection<int, PurchaseItem|SalesItem>  $items
     */
    private function describeItems(Collection $items): ?string
    {
        $names = $items->map(fn ($item) => $item->product?->name)->filter()->values();
        if ($names->isEmpty()) {
            return null;
        }

        $head = $names->take(3)->implode(', ');
        $rest = $names->count() - 3;

        return $rest > 0 ? "{$head} +{$rest} more" : $head;
    }

    /** COGS estimate for an export: cost price × quantity across the order. */
    private function estimatedCogs(SalesOrder $so): float
    {
        return round((float) $so->items->sum(
            fn ($item) => (float) ($item->product?->cost_price ?? 0) * (float) $item->quantity
        ), 2);
    }
}
