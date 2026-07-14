<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsappMessageResource extends JsonResource
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
            'channel' => $this->channel,
            'status' => $this->status,
            'link' => $this->link,
            'created_at' => $this->created_at,
        ];
    }
}
