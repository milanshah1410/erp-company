<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaymentTransaction
 */
class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gateway' => $this->gateway,
            'gateway_label' => $this->gateway ? config("payments.gateways.{$this->gateway}.label", ucfirst($this->gateway)) : null,
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice?->id,
                'invoice_number' => $this->invoice?->invoice_number,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
            ]),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'gateway_order_id' => $this->gateway_order_id,
            'gateway_payment_id' => $this->gateway_payment_id,
            'checkout_url' => $this->checkout_url,
            'pay_url' => $this->payUrl(),
            'failure_reason' => $this->failure_reason,
            'payment_voucher_id' => $this->payment_voucher_id,
            'voucher_number' => $this->whenLoaded('voucher', fn () => $this->voucher?->voucher_number),
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
