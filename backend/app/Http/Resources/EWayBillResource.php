<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EWayBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice->invoice_number),
            'customer_name' => $this->whenLoaded('invoice', fn () => $this->invoice->customer?->name),
            'ewb_no' => $this->ewb_no,
            'ewb_date' => $this->ewb_date,
            'valid_upto' => $this->valid_upto,
            'transport_mode' => $this->transport_mode,
            'transporter_id' => $this->transporter_id,
            'transporter_name' => $this->transporter_name,
            'transport_doc_no' => $this->transport_doc_no,
            'transport_doc_date' => $this->transport_doc_date?->toDateString(),
            'vehicle_no' => $this->vehicle_no,
            'vehicle_type' => $this->vehicle_type,
            'distance_km' => (float) $this->distance_km,
            'from_place' => $this->from_place,
            'from_pincode' => $this->from_pincode,
            'to_place' => $this->to_place,
            'to_pincode' => $this->to_pincode,
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
