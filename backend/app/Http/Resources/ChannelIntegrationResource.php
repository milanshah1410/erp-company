<?php

namespace App\Http\Resources;

use App\Services\Ecommerce\PlatformRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelIntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = PlatformRegistry::get($this->platform);

        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'platform_label' => $meta['label'] ?? ucfirst($this->platform),
            'icon' => $meta['icon'] ?? 'pi-globe',
            'name' => $this->name,
            'status' => $this->status,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'auto_sync_orders' => $this->auto_sync_orders,
            'auto_push_inventory' => $this->auto_push_inventory,
            'settings' => $this->settings ?? [],
            'capabilities' => $meta['capabilities'] ?? ['orders' => false, 'inventory' => false, 'listings' => false],
            // Never expose secret values — only which credential keys are set.
            'configured_credentials' => $this->credentialStatus($meta),
            'listings_count' => $this->whenCounted('listings'),
            'orders_count' => $this->whenCounted('orders'),
            'pending_orders_count' => $this->when(isset($this->pending_orders_count), fn () => (int) $this->pending_orders_count),
            'last_order_sync_at' => $this->last_order_sync_at,
            'last_inventory_sync_at' => $this->last_inventory_sync_at,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
        ];
    }

    /** @return array<string,bool> key => whether a value is stored */
    private function credentialStatus(?array $meta): array
    {
        $status = [];
        foreach ($meta['credentials'] ?? [] as $field) {
            $status[$field['key']] = ! empty($this->credential($field['key']));
        }

        return $status;
    }
}
