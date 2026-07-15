<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaymentGatewayAccount
 */
class PaymentGatewayAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta();

        // Report which credential fields are set — never the values themselves.
        $status = [];
        foreach (array_keys($meta['fields'] ?? []) as $key) {
            $status[$key] = ! empty($this->credential($key));
        }

        return [
            'id' => $this->id,
            'gateway' => $this->gateway,
            'gateway_label' => $meta['label'] ?? ucfirst($this->gateway),
            'label' => $this->label,
            'mode' => $this->mode,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'configured' => $this->isConfigured(),
            'missing_credentials' => $this->missingCredentials(),
            'credential_status' => $status,
            'currencies' => $meta['currencies'] ?? [],
            'export' => $meta['export'] ?? false,
            'created_at' => $this->created_at,
        ];
    }
}
