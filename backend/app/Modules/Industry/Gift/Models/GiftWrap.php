<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parcel at the wrap desk.
 *
 * A small job rather than a flag on a sale, because it behaves like a job: it is
 * taken in, it is promised for a time, someone does it, someone collects it, and
 * at Diwali there are thirty of them waiting. A boolean on an invoice answers
 * none of the questions the desk actually asks.
 *
 * Three fields carry the trade's rules:
 *
 *  - `hide_prices` defaults to true. The person receiving a gift must not be able
 *    to read what it cost, so the flag travels with the parcel instead of being
 *    remembered at the moment something is printed.
 *  - `charge` and `wrap_label` are snapshots. The option they came from can be
 *    repriced or retired next season, and what this parcel cost must not move.
 *  - `material_posted_at` is the idempotency guard. A parcel marked wrapped,
 *    reopened and marked wrapped again must not eat two sheets of paper.
 */
class GiftWrap extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'gift_wrap_number_prefix';

    /** Still work for the desk. */
    public const OPEN_STATUSES = ['pending'];

    /** Done with, one way or the other. */
    public const CLOSED_STATUSES = ['collected', 'cancelled'];

    protected $fillable = [
        'wrap_number', 'wrap_option_id', 'wrap_label', 'charge',
        'customer_id', 'invoice_id', 'sales_order_id', 'occasion_id',
        'recipient_name', 'recipient_phone', 'sender_name', 'message', 'hide_prices',
        'status', 'promised_at', 'wrapped_at', 'wrapped_by', 'collected_at',
        'warehouse_id', 'material_posted_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
        'hide_prices' => 'boolean',
        'promised_at' => 'datetime',
        'wrapped_at' => 'datetime',
        'collected_at' => 'datetime',
        'material_posted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'charge' => 0,
        'hide_prices' => true,
    ];

    public function option(): BelongsTo
    {
        return $this->belongsTo(GiftWrapOption::class, 'wrap_option_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function occasion(): BelongsTo
    {
        return $this->belongsTo(GiftOccasion::class, 'occasion_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function wrapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wrapped_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The desk's queue: taken in and not yet wrapped, soonest promise first. */
    public function scopeQueue(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES)
            ->orderByRaw('promised_at is null, promised_at asc')
            ->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Promised for a time that has passed, and still not wrapped.
     *
     * Derived, not stored — the same rule the trial room follows: a parcel
     * promised for six o'clock is late at ten past, whether or not anything has
     * run a sweep over the table.
     */
    public function isLate(): bool
    {
        return $this->isOpen()
            && $this->promised_at !== null
            && $this->promised_at->isPast();
    }

    /** Has the material for this parcel already been drawn down? */
    public function materialPosted(): bool
    {
        return $this->material_posted_at !== null;
    }
}
