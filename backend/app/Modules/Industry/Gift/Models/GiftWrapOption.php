<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing the shop will wrap a gift in — "Kraft paper + jute twine, ₹30".
 *
 * The master behind the wrap desk, and the reason wrapping needs no money path
 * of its own: an option points at an `is_service` product, so the charge is rung
 * through the till as an ordinary line like every other piece of labour in this
 * ERP. It also points at the *material* — the paper, the box, the ribbon — which
 * is real stock bought by the hundred, and is what makes a parcel deplete
 * something when it is wrapped.
 */
class GiftWrapOption extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** What the shop hands over, and how the desk has to handle it. */
    public const KINDS = ['paper', 'box', 'bag', 'basket', 'ribbon', 'card', 'other'];

    protected $fillable = [
        'code', 'name', 'kind', 'charge',
        'product_id', 'consumes_product_id', 'consume_quantity',
        'turnaround_minutes', 'notes', 'is_active',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
        'consume_quantity' => 'decimal:3',
        'turnaround_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'kind' => 'paper',
        'charge' => 0,
        'consume_quantity' => 1,
        'is_active' => true,
    ];

    /** The `is_service` product the charge is billed as. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The paper/box/ribbon itself — stock, drawn down per parcel. */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'consumes_product_id');
    }

    public function wraps(): HasMany
    {
        return $this->hasMany(GiftWrap::class, 'wrap_option_id');
    }

    /**
     * Is there anything to deplete when a parcel in this wrapping is finished?
     *
     * A shop may well charge for wrapping without tracking the paper — a roll of
     * ribbon is not worth a stock record to everyone — so this is a question, not
     * an assumption.
     */
    public function consumesStock(): bool
    {
        return $this->consumes_product_id !== null && (float) $this->consume_quantity > 0;
    }

    /**
     * A chargeable option with nothing to bill it as. Not an error — a shop may
     * set the charge up before creating the service product — but it is the one
     * thing about an option that quietly loses money, so the list surfaces it.
     */
    public function chargeUnbillable(): bool
    {
        return (float) $this->charge > 0 && $this->product_id === null;
    }
}
