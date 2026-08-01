<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated, part-paid, decorated-to-order cake.
 *
 * Only what a sales order cannot say lives here: the inscription, the weight, the
 * shape, the reference photo the customer sent, and the hour it must be ready.
 * The money goes onto a SalesOrder + Invoice on the 'cake' channel when the cake
 * is handed over — the same spine POS, B2B and the restaurant bill through.
 *
 * The advance is why this is a document and not just a future-dated invoice: the
 * shop takes ₹500 today against a ₹2,000 cake for Saturday, and needs to know all
 * week what it owes the oven and what the customer owes it.
 */
class CakeOrder extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'cake_order_number_prefix';

    /** States from which the order can still be worked or cancelled. */
    public const OPEN_STATUSES = ['booked', 'in_production', 'ready'];

    protected $fillable = [
        'order_number', 'customer_id', 'customer_name', 'customer_phone', 'product_id',
        'flavour', 'weight_kg', 'shape', 'tiers', 'is_eggless',
        'message_on_cake', 'design_notes', 'reference_image',
        'required_at', 'fulfilment', 'delivery_address', 'delivery_charge',
        'price', 'advance_amount', 'status',
        'sales_order_id', 'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'tiers' => 'integer',
        'is_eggless' => 'boolean',
        'required_at' => 'datetime',
        'delivery_charge' => 'decimal:2',
        'price' => 'decimal:2',
        'advance_amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The catalogue cake this was based on, if it was based on one. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Everything still to be made or collected — the bakery's board. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Due within the next `$hours`, soonest first: what the oven does next. */
    public function scopeDueWithin(Builder $query, int $hours = 24): Builder
    {
        return $query->open()
            ->whereBetween('required_at', [now(), now()->addHours($hours)])
            ->orderBy('required_at');
    }

    /** What the cake costs all-in, delivery included. */
    public function payable(): float
    {
        return round((float) $this->price + (float) $this->delivery_charge, 2);
    }

    /**
     * Still to collect on handover.
     *
     * Floored at zero: an advance larger than the price is a refund conversation,
     * not a negative balance to be shown on a docket as though the shop owed the
     * oven money.
     */
    public function balance(): float
    {
        return round(max(0, $this->payable() - (float) $this->advance_amount), 2);
    }

    /** Past its hour and still not handed over. */
    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            && $this->required_at->isPast();
    }
}
