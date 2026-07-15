<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice->invoice_number),
            'customer_name' => $this->whenLoaded('invoice', fn () => $this->invoice->customer?->name),
            'invoice_total' => $this->whenLoaded('invoice', fn () => (float) $this->invoice->total),
            'irn' => $this->irn,
            'ack_no' => $this->ack_no,
            'ack_date' => $this->ack_date,
            'signed_qr_code' => $this->signed_qr_code,
            'status' => $this->status,
            'cancel_reason_code' => $this->cancel_reason_code,
            'cancel_remarks' => $this->cancel_remarks,
            'cancelled_at' => $this->cancelled_at,
            'error_message' => $this->error_message,
            'mode' => $this->mode,
            'request_payload' => $this->when($request->boolean('with_payload'), $this->request_payload),
            'created_at' => $this->created_at,
        ];
    }
}
