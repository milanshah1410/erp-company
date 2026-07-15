<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a local product/variant to its listing on an e-commerce channel, so
 * inventory pushes and order imports can translate between the ERP SKU and the
 * marketplace's identifiers.
 */
class ChannelListing extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'channel_integration_id', 'product_id', 'variant_id',
        'external_id', 'external_sku', 'listing_title', 'price', 'status', 'last_synced_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'last_synced_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ChannelIntegration::class, 'channel_integration_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
