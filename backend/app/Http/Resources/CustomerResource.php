<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Compute the receivable once; available credit derives from it.
        $outstanding = $this->outstandingBalance();
        $limit = (float) $this->credit_limit;
        $availableCredit = $limit > 0 ? round($limit - $outstanding, 2) : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => $this->type,
            'customer_category_id' => $this->customer_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'default_price_level' => $this->category->default_price_level,
            ] : null),
            'gst_number' => $this->gst_number,
            'gst_registration_type' => $this->gst_registration_type,
            'pan' => $this->pan,
            'place_of_supply' => $this->place_of_supply,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'pincode' => $this->pincode,
            'credit_limit' => $this->credit_limit,
            'opening_balance' => $this->opening_balance,
            'default_price_level' => $this->default_price_level,
            'effective_price_level' => $this->effectivePriceLevel(),
            'outstanding_balance' => $outstanding,
            'available_credit' => $availableCredit,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
