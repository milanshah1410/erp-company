<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Modules\DeviceManager\Support\DeviceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One configured piece of hardware. `type` selects the driver interface
 * (barcode / printer / weight_scale / rfid / biometric) and `driver` selects the
 * brand adapter from App\Modules\DeviceManager's registry.
 *
 * `mode` is the switch that makes hardware optional during development: in
 * 'simulator' mode every adapter answers from its own built-in fake — mock
 * scans, fake weight frames, virtual fingerprint punches, print previews — using
 * the same normalisation code the live path runs, so the flow under test is the
 * real one.
 */
class Device extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'type', 'driver', 'code', 'name', 'location', 'mode', 'is_active', 'is_default',
        'settings', 'credentials', 'warehouse_id', 'biometric_device_id',
    ];

    protected $casts = [
        'settings' => 'array',
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    /** Secrets never leave the server, not even by accident. */
    protected $hidden = ['credentials'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The HR biometric-device row this terminal feeds punches into, if any. */
    public function biometricDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function isSimulated(): bool
    {
        return $this->mode === 'simulator';
    }

    /** Registry metadata for this device's brand adapter (null if unknown). */
    public function driverMeta(): ?array
    {
        return DeviceRegistry::driver($this->type, $this->driver);
    }
}
