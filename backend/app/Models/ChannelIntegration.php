<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connected e-commerce channel — one marketplace/storefront account
 * (Shopify, WooCommerce, Magento, Amazon, Flipkart, Meesho, IndiaMART).
 * Credentials are stored encrypted; non-secret options live in `settings`.
 */
class ChannelIntegration extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'platform', 'name', 'status', 'credentials', 'settings', 'warehouse_id',
        'auto_sync_orders', 'auto_push_inventory',
        'last_order_sync_at', 'last_inventory_sync_at', 'last_error',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'auto_sync_orders' => 'boolean',
        'auto_push_inventory' => 'boolean',
        'last_order_sync_at' => 'datetime',
        'last_inventory_sync_at' => 'datetime',
    ];

    /** Keep the encrypted blob out of any accidental serialisation. */
    protected $hidden = ['credentials'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ChannelOrder::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(ChannelSyncLog::class);
    }

    /** A single credential value (returns null when unset). */
    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    /** A single non-secret setting value. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
