<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shipment payload for the Import & Export Logistics screens. Alongside the
 * stored columns it exposes the figures every board needs pre-computed —
 * base-currency value, outstanding amount, ETA variance — so the client never
 * re-derives money or dates.
 */
class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_number' => $this->shipment_number,
            'direction' => $this->direction,
            'status' => $this->status,
            'shipment_date' => $this->shipment_date?->toDateString(),

            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'party' => $this->whenLoaded($this->direction === 'import' ? 'supplier' : 'customer', fn () => $this->partyName()),
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder?->po_number),
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder?->so_number),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),

            // ERP documents this shipment produced (ShipmentIntegrationController).
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'purchase_invoice' => $this->whenLoaded('purchaseInvoice', fn () => $this->purchaseInvoice?->invoice_number),
            'goods_receipt_id' => $this->goods_receipt_id,
            'goods_receipt' => $this->whenLoaded('goodsReceipt', fn () => $this->goodsReceipt?->grn_number),
            'customer_invoice_id' => $this->invoice_id,
            'customer_invoice' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
            'dispatch_plan_id' => $this->dispatch_plan_id,
            'dispatch_plan' => $this->whenLoaded('dispatchPlan', fn () => $this->dispatchPlan?->dispatch_number),

            'mode' => $this->mode,
            'incoterm' => $this->incoterm,
            'shipping_line_id' => $this->shipping_line_id,
            'shipping_line' => $this->whenLoaded('shippingLine', fn () => $this->shippingLine?->name),
            'airline_id' => $this->airline_id,
            'airline' => $this->whenLoaded('airline', fn () => $this->airline?->name),
            // Whichever of the two actually carries this shipment, so lists can
            // show one "Carrier" column regardless of mode.
            'carrier' => $this->when(
                $this->relationLoaded('shippingLine') || $this->relationLoaded('airline'),
                fn () => $this->carrierName(),
            ),
            'freight_forwarder_id' => $this->freight_forwarder_id,
            'freight_forwarder' => $this->whenLoaded('freightForwarder', fn () => $this->freightForwarder?->name),
            'cha_agent_id' => $this->cha_agent_id,
            'cha_agent' => $this->whenLoaded('chaAgent', fn () => $this->chaAgent?->name),
            'transporter_id' => $this->transporter_id,
            'transporter' => $this->whenLoaded('transporter', fn () => $this->transporter?->name),
            'bl_awb_number' => $this->bl_awb_number,
            'bl_awb_date' => $this->bl_awb_date?->toDateString(),
            'vessel_name' => $this->vessel_name,
            'voyage_number' => $this->voyage_number,
            'booking_number' => $this->booking_number,
            'booking_date' => $this->booking_date?->toDateString(),
            'si_cutoff' => $this->si_cutoff?->toDateString(),
            'cargo_cutoff' => $this->cargo_cutoff?->toDateString(),
            'origin_country_id' => $this->origin_country_id,
            'origin_country' => $this->whenLoaded('originCountry', fn () => $this->originCountry?->name),
            'destination_country_id' => $this->destination_country_id,
            'destination_country' => $this->whenLoaded('destinationCountry', fn () => $this->destinationCountry?->name),
            'port_of_loading_id' => $this->port_of_loading_id,
            'port_of_loading' => $this->whenLoaded('portOfLoading', fn () => $this->portOfLoading?->name),
            'port_of_discharge_id' => $this->port_of_discharge_id,
            'port_of_discharge' => $this->whenLoaded('portOfDischarge', fn () => $this->portOfDischarge?->name),
            'final_place' => $this->final_place,

            'etd' => $this->etd?->toDateString(),
            'eta' => $this->eta?->toDateString(),
            'atd' => $this->atd?->toDateString(),
            'ata' => $this->ata?->toDateString(),
            'eta_variance_days' => $this->etaVarianceDays(),

            'goods_description' => $this->goods_description,
            'packages' => $this->packages,
            'gross_weight_kg' => $this->gross_weight_kg,
            'net_weight_kg' => $this->net_weight_kg,
            'cbm' => $this->cbm,

            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'invoice_value' => $this->invoice_value,
            'invoice_value_base' => $this->invoiceValueBase(),
            'freight_amount' => $this->freight_amount,
            'insurance_amount' => $this->insurance_amount,
            'freight_insurance_base' => $this->freightInsuranceBase(),
            'goods_cost' => $this->goods_cost,

            'customs_status' => $this->customs_status,
            'customs_entry_type' => $this->customs_entry_type,
            'customs_entry_number' => $this->customs_entry_number,
            'customs_entry_date' => $this->customs_entry_date?->toDateString(),
            'icegate_reference' => $this->icegate_reference,
            'assessable_value' => $this->assessable_value,
            // Export half of the entry: what the shipping bill was assessed on
            // and the incentives claimed against it.
            'fob_value' => $this->fob_value,
            'fob_value_base' => $this->fobValueBase(),
            'drawback_amount' => $this->drawback_amount,
            'rodtep_amount' => $this->rodtep_amount,
            'leo_date' => $this->leo_date?->toDateString(),
            'duty_bcd' => $this->duty_bcd,
            'duty_igst' => $this->duty_igst,
            'duty_cess' => $this->duty_cess,
            'duty_other' => $this->duty_other,
            'duty_total' => $this->duty_total,
            'duty_paid_at' => $this->duty_paid_at,
            'customs_cleared_at' => $this->customs_cleared_at,

            'payment_status' => $this->payment_status,
            'amount_settled' => $this->amount_settled,
            'outstanding_amount' => $this->outstandingAmount(),
            'payment_due_date' => $this->payment_due_date?->toDateString(),
            'lc_number' => $this->lc_number,
            'lc_expiry' => $this->lc_expiry?->toDateString(),

            'remarks' => $this->remarks,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,

            'containers_count' => $this->whenCounted('containers'),
            'documents_count' => $this->whenCounted('documents'),
            'pending_documents_count' => $this->whenCounted('pendingDocuments'),
            'expenses_count' => $this->whenCounted('expenses'),
            'packing_items_count' => $this->whenCounted('packingItems'),

            // Where the leg has got to, step by step — the Import / Export Chain
            // panels read this rather than re-deriving "done" from a dozen
            // columns. Detail loads only: it walks the linked documents, which
            // the lists deliberately do not eager-load, so the presence of the
            // leg's own child relation is what gates it.
            'chain' => $this->when(
                $this->relationLoaded($this->direction === 'export' ? 'packingItems' : 'containers'),
                fn () => $this->direction === 'export' ? $this->exportChain() : $this->importChain(),
            ),

            'containers' => ShipmentContainerResource::collection($this->whenLoaded('containers')),
            'packing_items' => ShipmentPackingItemResource::collection($this->whenLoaded('packingItems')),
            'documents' => ShipmentDocumentResource::collection($this->whenLoaded('documents')),
            'events' => ShipmentEventResource::collection($this->whenLoaded('events')),
            'expenses' => ShipmentExpenseResource::collection($this->whenLoaded('expenses')),
            'payments' => ShipmentPaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
