<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\EWayBill;
use App\Models\GstRegistration;
use App\Models\Invoice;
use App\Support\GstStateCodes;
use Carbon\Carbon;
use RuntimeException;

/**
 * Builds an NIC e-way bill payload from a sales Invoice and obtains an EWB
 * number. Mirrors {@see EInvoiceService}: 'offline' generates deterministically
 * so the flow works without a live GالسP; 'gsp' is the transmission seam.
 */
class EWayBillService
{
    /**
     * Generate an e-way bill for an invoice using the supplied transport details.
     *
     * @param  array<string,mixed>  $transport
     */
    public function generate(Invoice $invoice, array $transport, ?int $userId = null): EWayBill
    {
        $existing = $invoice->eWayBill()->first();
        if ($existing && $existing->status === 'generated') {
            throw new RuntimeException('An e-way bill already exists for this invoice.');
        }

        $payload = $this->buildPayload($invoice, $transport);

        try {
            $response = $this->transmit($payload, $invoice);
        } catch (RuntimeException $e) {
            $invoice->eWayBill()->updateOrCreate(
                ['invoice_id' => $invoice->id],
                array_merge($transport, [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'request_payload' => $payload,
                    'mode' => config('gst.eway_mode'),
                    'generated_by' => $userId,
                ]),
            );
            throw $e;
        }

        return $invoice->eWayBill()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            array_merge($transport, [
                'ewb_no' => $response['ewayBillNo'],
                'ewb_date' => $response['ewayBillDate'],
                'valid_upto' => $response['validUpto'],
                'from_place' => $payload['fromPlace'],
                'from_pincode' => $payload['fromPincode'],
                'to_place' => $payload['toPlace'],
                'to_pincode' => $payload['toPincode'],
                'status' => 'generated',
                'error_message' => null,
                'cancelled_at' => null,
                'cancel_reason_code' => null,
                'cancel_remarks' => null,
                'request_payload' => $payload,
                'response_payload' => $response,
                'mode' => config('gst.eway_mode'),
                'generated_by' => $userId,
            ]),
        );
    }

    /** Cancel an EWB (allowed within 24h). */
    public function cancel(EWayBill $ewb, string $reasonCode, string $remarks): EWayBill
    {
        if ($ewb->status !== 'generated') {
            throw new RuntimeException('Only a generated e-way bill can be cancelled.');
        }
        if ($ewb->ewb_date && $ewb->ewb_date->lt(now()->subDay())) {
            throw new RuntimeException('An e-way bill can only be cancelled within 24 hours of generation.');
        }

        $ewb->update([
            'status' => 'cancelled',
            'cancel_reason_code' => $reasonCode,
            'cancel_remarks' => $remarks,
            'cancelled_at' => now(),
        ]);

        return $ewb;
    }

    /** Update the conveyance (Part-B) — e.g. on trans-shipment / vehicle change. */
    public function updateVehicle(EWayBill $ewb, string $vehicleNo, string $mode): EWayBill
    {
        if ($ewb->status !== 'generated') {
            throw new RuntimeException('Vehicle can only be updated on a generated e-way bill.');
        }

        $ewb->update(['vehicle_no' => $vehicleNo, 'transport_mode' => $mode]);

        return $ewb;
    }

    /**
     * @param  array<string,mixed>  $transport
     * @return array<string,mixed>
     */
    public function buildPayload(Invoice $invoice, array $transport): array
    {
        $invoice->loadMissing(['customer', 'salesOrder.items.product']);
        $order = $invoice->salesOrder;
        if (! $order || $order->items->isEmpty()) {
            throw new RuntimeException('Invoice has no line items to build an e-way bill from.');
        }

        [$seller, $sellerStateCode] = $this->seller();
        $buyer = $invoice->customer;
        $buyerStateCode = $this->buyerStateCode($buyer, $sellerStateCode);
        $interstate = $buyerStateCode !== $sellerStateCode;

        $assessable = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;
        $items = [];
        foreach ($order->items as $item) {
            $tax = (float) $item->tax_amount;
            $lineAssessable = round((float) $item->line_total - $tax, 2);
            $assessable += $lineAssessable;
            if ($interstate) {
                $igst += $tax;
            } else {
                $cgst += $tax / 2;
                $sgst += $tax / 2;
            }
            $items[] = [
                'productName' => $item->product?->name ?? 'Item',
                'hsnCode' => $item->product?->hsn_code ?: '9999',
                'quantity' => (float) $item->quantity,
                'taxableAmount' => $lineAssessable,
                'gstRate' => (float) $item->tax_percent,
            ];
        }

        return [
            'supplyType' => 'O', // Outward
            'subSupplyType' => '1', // Supply
            'docType' => 'INV',
            'docNo' => $invoice->invoice_number,
            'docDate' => Carbon::parse($invoice->invoice_date)->format('d/m/Y'),
            'fromGstin' => $seller['gstin'],
            'fromTrdName' => $seller['legal_name'],
            'fromPlace' => $transport['from_place'] ?? $seller['city'],
            'fromPincode' => $transport['from_pincode'] ?? $seller['pincode'],
            'fromStateCode' => $sellerStateCode,
            'toGstin' => $buyer?->gst_number ?: 'URP',
            'toTrdName' => $buyer?->company_name ?: ($buyer?->name ?? 'Cash Customer'),
            'toPlace' => $transport['to_place'] ?? $buyer?->city,
            'toPincode' => $transport['to_pincode'] ?? $buyer?->pincode,
            'toStateCode' => $buyerStateCode,
            'totalValue' => round($assessable, 2),
            'cgstValue' => round($cgst, 2),
            'sgstValue' => round($sgst, 2),
            'igstValue' => round($igst, 2),
            'totInvValue' => round((float) $invoice->total, 2),
            'transMode' => $this->transModeCode($transport['transport_mode'] ?? 'road'),
            'transporterId' => $transport['transporter_id'] ?? null,
            'transporterName' => $transport['transporter_name'] ?? null,
            'transDocNo' => $transport['transport_doc_no'] ?? null,
            'transDistance' => (float) ($transport['distance_km'] ?? 0),
            'vehicleNo' => $transport['vehicle_no'] ?? null,
            'vehicleType' => ($transport['vehicle_type'] ?? 'regular') === 'over_dimensional_cargo' ? 'O' : 'R',
            'itemList' => $items,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function transmit(array $payload, Invoice $invoice): array
    {
        if (config('gst.eway_mode') === 'gsp') {
            throw new RuntimeException('GSP e-way bill transmission is not configured. Set GST_EWAY_MODE=offline or wire a provider.');
        }

        // Deterministic 12-digit EWB number from the seller GSTIN + doc number.
        $digits = preg_replace('/\D/', '', hash('crc32b', $payload['fromGstin'].$payload['docNo']).'000000000000');
        $ewbNo = substr(str_pad($digits, 12, '0'), 0, 12);

        // Validity: 1 day per 200 km (or part thereof), minimum 1 day.
        $days = max(1, (int) ceil(((float) $payload['transDistance']) / 200));
        $now = now();

        return [
            'ewayBillNo' => $ewbNo,
            'ewayBillDate' => $now->format('Y-m-d H:i:s'),
            'validUpto' => $now->copy()->addDays($days)->endOfDay()->format('Y-m-d H:i:s'),
            'status' => 'ACT',
        ];
    }

    private function transModeCode(string $mode): string
    {
        return match ($mode) {
            'rail' => '2',
            'air' => '3',
            'ship' => '4',
            default => '1', // road
        };
    }

    /** @return array{0:array<string,?string>,1:string} */
    private function seller(): array
    {
        $reg = GstRegistration::query()->where('is_active', true)
            ->orderByDesc('is_primary')->first();
        $company = Company::query()->orderBy('id')->first();

        $gstin = $reg?->gstin ?: $company?->gstin;
        if (! $gstin) {
            throw new RuntimeException('No company GSTIN is configured. Add a GST registration first.');
        }

        return [[
            'gstin' => $gstin,
            'legal_name' => $reg?->legal_name ?: $company?->legal_name ?: $company?->name,
            'city' => $company?->city,
            'pincode' => $company?->pincode,
        ], substr($gstin, 0, 2)];
    }

    private function buyerStateCode(?\App\Models\Customer $buyer, string $sellerStateCode): string
    {
        if ($buyer?->gst_number && ctype_digit(substr($buyer->gst_number, 0, 2))) {
            return substr($buyer->gst_number, 0, 2);
        }

        return GstStateCodes::code($buyer?->place_of_supply)
            ?? GstStateCodes::code($buyer?->state)
            ?? $sellerStateCode;
    }
}
