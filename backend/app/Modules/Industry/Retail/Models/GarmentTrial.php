<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trial: garments that left the shop for someone to try at home.
 *
 * The document exists because the goods are gone and the money is not there
 * yet — which is neither a sale nor a stock adjustment, and pretending it is
 * either loses the shop's actual exposure. Every question the counter asks about
 * it is a custody question: who has it, what did they take, when did they say
 * they would be back, and how much of it is still out.
 */
class GarmentTrial extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'garment_trial_number_prefix';

    /** Statuses that no longer accept issue or return activity. */
    public const CLOSED_STATUSES = ['closed', 'converted', 'cancelled'];

    protected $fillable = [
        'trial_number', 'customer_id', 'warehouse_id',
        'issued_at', 'due_at', 'status',
        'deposit_amount', 'deposit_mode', 'deposit_refunded',
        'closed_at', 'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_at' => 'date',
        'closed_at' => 'datetime',
        'deposit_amount' => 'decimal:2',
        'deposit_refunded' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GarmentTrialItem::class, 'trial_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Past its promised date and still holding goods.
     *
     * Derived rather than stored, for the same reason a warranty's expiry is:
     * a trial that fell due at midnight is overdue at nine the next morning
     * whether or not anything has run a sweep over the table.
     */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->endOfDay()->isPast()
            && $this->outstandingQuantity() > 0;
    }

    /** How much of what went out is still out. */
    public function outstandingQuantity(): float
    {
        return round((float) $this->items->sum(fn (GarmentTrialItem $item) => $item->outstandingQuantity()), 3);
    }

    /** What the outstanding pieces were quoted at — the shop's exposure. */
    public function outstandingValue(): float
    {
        return round((float) $this->items->sum(
            fn (GarmentTrialItem $item) => $item->outstandingQuantity() * (float) $item->unit_price,
        ), 2);
    }
}
