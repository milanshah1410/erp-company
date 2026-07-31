<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Modules\DeviceManager\Support\DeviceRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $typeMeta = DeviceRegistry::TYPES[$this->type] ?? null;
        $driverMeta = $this->driverMeta();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $typeMeta['label'] ?? ucfirst((string) $this->type),
            'icon' => $typeMeta['icon'] ?? 'pi pi-desktop',
            'actions' => $typeMeta['actions'] ?? [],
            'simulate' => $typeMeta['simulate'] ?? null,
            'simulate_label' => $typeMeta['simulate_label'] ?? 'Simulate',
            'driver' => $this->driver,
            'driver_label' => $driverMeta['label'] ?? $this->driver,
            'driver_models' => $driverMeta['models'] ?? null,
            'driver_help' => $driverMeta['help'] ?? null,
            'code' => $this->code,
            'name' => $this->name,
            'location' => $this->location,
            'mode' => $this->mode,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'settings' => $this->settings ?? [],
            // Which secrets are stored, never their values.
            'configured_credentials' => $this->credentialStatus($driverMeta),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'biometric_device_id' => $this->biometric_device_id,
            'biometric_device' => $this->whenLoaded('biometricDevice', fn () => $this->biometricDevice ? [
                'id' => $this->biometricDevice->id,
                'name' => $this->biometricDevice->name,
            ] : null),
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at,
            'last_tested_at' => $this->last_tested_at,
            'last_error' => $this->last_error,
            'events_count' => (int) $this->events_count,
            'created_at' => $this->created_at,
        ];
    }

    /** @return array<string,bool> */
    private function credentialStatus(?array $driverMeta): array
    {
        $status = [];
        foreach ($driverMeta['credentials'] ?? [] as $field) {
            $status[$field['key']] = ! empty($this->credential($field['key']));
        }

        return $status;
    }
}
