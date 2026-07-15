<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
            ]),
            'phone' => $this->phone,
            'template' => $this->template,
            'body' => $this->body,
            'provider' => $this->provider,
            'status' => $this->status,
            'provider_message_id' => $this->provider_message_id,
            'error' => $this->error,
            'created_at' => $this->created_at,
        ];
    }
}
