<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Modules\DeviceManager\Support\DeviceRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $device = $this->route('device');
        $tenantId = app(TenantContext::class)->id();
        $creating = $device === null;

        return [
            // type/driver are the plugin identity; changing them on an existing
            // device would invalidate its stored settings, so they are create-only.
            'type' => [$creating ? 'required' : 'prohibited', 'string', Rule::in(DeviceRegistry::types())],
            'driver' => [$creating ? 'required' : 'prohibited', 'string', 'max:40'],

            'code' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('devices', 'code')->where('tenant_id', $tenantId)->ignore($device?->id),
            ],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'mode' => ['sometimes', Rule::in(['live', 'simulator'])],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'biometric_device_id' => ['nullable', 'integer', 'exists:biometric_devices,id'],
        ];
    }

    /**
     * The driver must exist for the chosen type, and a live device must carry
     * whatever its adapter marked required — a half-configured device that only
     * fails at the counter is worse than a rejected save.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var \App\Models\Device|null $device */
            $device = $this->route('device');

            $type = (string) ($this->input('type') ?? $device?->type ?? '');
            $driver = (string) ($this->input('driver') ?? $device?->driver ?? '');

            if ($type === '' || $driver === '') {
                return;
            }

            $meta = DeviceRegistry::driver($type, $driver);
            if ($meta === null) {
                $validator->errors()->add('driver', "\"{$driver}\" is not a registered adapter for {$type} devices.");

                return;
            }

            $mode = (string) ($this->input('mode') ?? $device?->mode ?? 'simulator');
            if ($mode !== 'live') {
                return;   // Simulator mode needs nothing configured — that is the point.
            }

            $settings = array_merge($device?->settings ?? [], $this->input('settings') ?? []);
            foreach ($meta['settings'] as $field) {
                if (($field['required'] ?? false) && trim((string) ($settings[$field['key']] ?? '')) === '') {
                    $validator->errors()->add("settings.{$field['key']}", "{$field['label']} is required in Live mode.");
                }
            }

            $incoming = $this->input('credentials') ?? [];
            foreach ($meta['credentials'] as $field) {
                $stored = $device?->credential($field['key']);
                $given = trim((string) ($incoming[$field['key']] ?? ''));
                if (($field['required'] ?? false) && $given === '' && ($stored === null || $stored === '')) {
                    $validator->errors()->add("credentials.{$field['key']}", "{$field['label']} is required in Live mode.");
                }
            }
        });
    }
}
