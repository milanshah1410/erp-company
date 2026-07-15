<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\EInvoice;
use App\Models\GstRegistration;
use App\Models\Invoice;
use App\Support\GstStateCodes;
use Carbon\Carbon;
use RuntimeException;

/**
 * Builds a NIC e-invoice (schema 1.1) payload from a sales Invoice and obtains
 * an IRN. Two modes (config gst.einvoice_mode):
 *   - offline: generate a deterministic IRN + signed-QR locally so the flow is
 *     fully usable without a live connection (dev / not-yet-onboarded tenants).
 *   - gsp: hand the payload to a real GST Suvidha Provider (not wired here —
 *     the seam is {@see transmit()}).
 */
class EInvoiceService
{
    /** Generate (or return the existing) e-invoice for a sales invoice. */
    public function generate(Invoice $invoice, ?int $userId = null): EInvoice
    {
        $existing = $invoice->eInvoice()->first();
        if ($existing && $existing->status === 'generated') {
            throw new RuntimeException('An e-invoice (IRN) already exists for this invoice.');
        }

        $payload = $this->buildPayload($invoice);
        $sellerGstin = $payload['SellerDtls']['Gstin'];

        try {
            $response = $this->transmit($payload, $sellerGstin, $invoice);
        } catch (RuntimeException $e) {
            $invoice->eInvoice()->updateOrCreate(
                ['invoice_id' => $invoice->id],
                [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'request_payload' => $payload,
                    'mode' => config('gst.einvoice_mode'),
                    'generated_by' => $userId,
                ],
            );
            throw $e;
        }

        return $invoice->eInvoice()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'irn' => $response['Irn'],
                'ack_no' => $response['AckNo'],
                'ack_date' => $response['AckDt'],
                'signed_qr_code' => $response['SignedQRCode'],
                'signed_invoice' => $response['SignedInvoice'] ?? null,
                'status' => 'generated',
                'error_message' => null,
                'cancel_reason_code' => null,
                'cancel_remarks' => null,
                'cancelled_at' => null,
                'request_payload' => $payload,
                'response_payload' => $response,
                'mode' => config('gst.einvoice_mode'),
                'generated_by' => $userId,
            ],
        );
    }

    /** Cancel an IRN (allowed within 24h of generation per GST rules). */
    public function cancel(EInvoice $eInvoice, string $reasonCode, string $remarks): EInvoice
    {
        if ($eInvoice->status !== 'generated') {
            throw new RuntimeException('Only a generated e-invoice can be cancelled.');
        }
        if ($eInvoice->ack_date && $eInvoice->ack_date->lt(now()->subDay())) {
            throw new RuntimeException('An IRN can only be cancelled within 24 hours of generation.');
        }

        $eInvoice->update([
            'status' => 'cancelled',
            'cancel_reason_code' => $reasonCode,
            'cancel_remarks' => $remarks,
            'cancelled_at' => now(),
        ]);

        return $eInvoice;
    }

    /**
     * Assemble the e-invoice JSON. Item-level CGST/SGST/IGST are split from the
     * single stored tax amount using place-of-supply vs the seller's state.
     *
     * @return array<string,mixed>
     */
    public function buildPayload(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'salesOrder.items.product']);
        $order = $invoice->salesOrder;
        if (! $order || $order->items->isEmpty()) {
            throw new RuntimeException('Invoice has no line items to build an e-invoice from.');
        }

        [$seller, $sellerStateCode] = $this->seller();
        $buyer = $invoice->customer;
        $buyerStateCode = $this->buyerStateCode($buyer, $sellerStateCode);
        $interstate = $buyerStateCode !== $sellerStateCode;

        $items = [];
        $totals = ['assessable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'total' => 0.0];

        foreach ($order->items as $i => $item) {
            $lineTotal = (float) $item->line_total;
            $tax = (float) $item->tax_amount;
            $assessable = round($lineTotal - $tax, 2);
            $rate = (float) $item->tax_percent;

            $cgst = $interstate ? 0.0 : round($tax / 2, 2);
            $sgst = $interstate ? 0.0 : round($tax / 2, 2);
            $igst = $interstate ? round($tax, 2) : 0.0;

            $items[] = [
                'SlNo' => (string) ($i + 1),
                'PrdDesc' => $item->product?->name ?? 'Item',
                'IsServc' => 'N',
                'HsnCd' => $item->product?->hsn_code ?: '9999',
                'Qty' => (float) $item->quantity,
                'Unit' => 'PCS',
                'UnitPrice' => (float) $item->unit_price,
                'TotAmt' => round((float) $item->unit_price * (float) $item->quantity, 2),
                'Discount' => (float) $item->discount_amount,
                'AssAmt' => $assessable,
                'GstRt' => $rate,
                'CgstAmt' => $cgst,
                'SgstAmt' => $sgst,
                'IgstAmt' => $igst,
                'TotItemVal' => round($assessable + $tax, 2),
            ];

            $totals['assessable'] += $assessable;
            $totals['cgst'] += $cgst;
            $totals['sgst'] += $sgst;
            $totals['igst'] += $igst;
            $totals['total'] += round($assessable + $tax, 2);
        }

        return [
            'Version' => config('gst.schema_version', '1.1'),
            'TranDtls' => [
                'TaxSch' => 'GST',
                'SupTyp' => ($buyer && $buyer->gst_number) ? 'B2B' : 'B2C',
                'RegRev' => 'N',
            ],
            'DocDtls' => [
                'Typ' => 'INV',
                'No' => $invoice->invoice_number,
                'Dt' => Carbon::parse($invoice->invoice_date)->format('d/m/Y'),
            ],
            'SellerDtls' => [
                'Gstin' => $seller['gstin'],
                'LglNm' => $seller['legal_name'],
                'Addr1' => $seller['address'],
                'Loc' => $seller['city'],
                'Pin' => $seller['pincode'],
                'Stcd' => $sellerStateCode,
            ],
            'BuyerDtls' => [
                'Gstin' => $buyer?->gst_number ?: 'URP', // Unregistered Person
                'LglNm' => $buyer?->company_name ?: ($buyer?->name ?? 'Cash Customer'),
                'Pos' => $buyerStateCode,
                'Addr1' => $buyer?->address,
                'Loc' => $buyer?->city,
                'Pin' => $buyer?->pincode,
                'Stcd' => $buyerStateCode,
            ],
            'ItemList' => $items,
            'ValDtls' => [
                'AssVal' => round($totals['assessable'], 2),
                'CgstVal' => round($totals['cgst'], 2),
                'SgstVal' => round($totals['sgst'], 2),
                'IgstVal' => round($totals['igst'], 2),
                'RndOffAmt' => 0,
                'TotInvVal' => round($totals['total'], 2),
            ],
        ];
    }

    /**
     * Transmit the payload and return the IRP acknowledgement. In offline mode
     * this is generated deterministically; the 'gsp' branch is the seam where a
     * real provider client is plugged in.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function transmit(array $payload, string $sellerGstin, Invoice $invoice): array
    {
        if (config('gst.einvoice_mode') === 'gsp') {
            throw new RuntimeException('GSP e-invoice transmission is not configured. Set GST_EINVOICE_MODE=offline or wire a provider.');
        }

        $fy = $this->financialYear(Carbon::parse($invoice->invoice_date));
        $irn = hash('sha256', $sellerGstin.$payload['DocDtls']['No'].$payload['DocDtls']['Typ'].$fy);

        // Signed QR carries the fields a verifier scans; real IRP returns a JWT.
        $qrData = [
            'SellerGstin' => $sellerGstin,
            'BuyerGstin' => $payload['BuyerDtls']['Gstin'],
            'DocNo' => $payload['DocDtls']['No'],
            'DocTyp' => $payload['DocDtls']['Typ'],
            'DocDt' => $payload['DocDtls']['Dt'],
            'TotInvVal' => $payload['ValDtls']['TotInvVal'],
            'ItemCnt' => count($payload['ItemList']),
            'MainHsnCode' => $payload['ItemList'][0]['HsnCd'] ?? null,
            'Irn' => $irn,
        ];

        return [
            'Irn' => $irn,
            'AckNo' => (string) (int) hexdec(substr($irn, 0, 8)),
            'AckDt' => now()->format('Y-m-d H:i:s'),
            'SignedQRCode' => $this->pseudoJwt($qrData),
            'Status' => 'ACT',
        ];
    }

    /** Build an unsigned JWT-shaped token (header.payload.sig) for the QR. */
    private function pseudoJwt(array $data): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');

        return $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64(['data' => json_encode($data)]).'.'.$b64('offline');
    }

    /** @return array{0:array<string,?string>,1:string} seller fields + state code */
    private function seller(): array
    {
        $reg = GstRegistration::query()->where('is_active', true)
            ->orderByDesc('is_primary')->first();
        $company = Company::query()->orderBy('id')->first();

        $gstin = $reg?->gstin ?: $company?->gstin;
        if (! $gstin) {
            throw new RuntimeException('No company GSTIN is configured. Add a GST registration first.');
        }
        $stateCode = substr($gstin, 0, 2);

        return [[
            'gstin' => $gstin,
            'legal_name' => $reg?->legal_name ?: $company?->legal_name ?: $company?->name,
            'address' => $reg?->address ?: $company?->address,
            'city' => $company?->city,
            'pincode' => $company?->pincode,
        ], $stateCode];
    }

    /** Buyer's place-of-supply state code, defaulting to the seller's (intra). */
    private function buyerStateCode(?Customer $buyer, string $sellerStateCode): string
    {
        if ($buyer?->gst_number && ctype_digit(substr($buyer->gst_number, 0, 2))) {
            return substr($buyer->gst_number, 0, 2);
        }

        return GstStateCodes::code($buyer?->place_of_supply)
            ?? GstStateCodes::code($buyer?->state)
            ?? $sellerStateCode;
    }

    /** Indian financial year label (e.g. "2026-27") for the IRN hash. */
    private function financialYear(Carbon $date): string
    {
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;

        return $startYear.'-'.substr((string) ($startYear + 1), 2);
    }
}
