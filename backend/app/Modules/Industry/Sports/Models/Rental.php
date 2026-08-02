<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Models;

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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One hire agreement: who has what, until when, and against what deposit.
 *
 * The booking and the hire are the same row at different points of its life, not
 * two documents. A shop takes six bikes for Sunday on Tuesday and hands them over
 * on Sunday morning; modelling that as a reservation plus a separate hire is how
 * a fleet ends up double-booked, because availability then has to remember to
 * read both and one day it does not.
 *
 * Three things this model is careful about:
 *
 *  - **Overdue is derived.** `out` plus a due time behind us, allowing for the
 *    grace hours agreed on the day. No stored flag, no nightly sweep — the
 *    overdue board is the screen the module is opened for, and it has to be right
 *    at the instant somebody looks at it rather than as of the last time a job
 *    ran.
 *  - **The deposit is not revenue.** It is the customer's money held against the
 *    kit coming back. Only the part applied against a real charge is ever earned,
 *    and it becomes earned by settling an invoice as a tender, not by being
 *    quietly counted as a sale.
 *  - **The lines are the truth about what is out.** A partial return is normal,
 *    so "is this contract still out?" is a question about lines, not about a flag
 *    somebody remembered to move.
 */
class Rental extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'sports_rentals';

    protected static string $numberSettingKey = 'sports_rental_number_prefix';

    /** Statuses that hold kit against the fleet's availability. */
    public const HOLDING_STATUSES = ['booked', 'out'];

    /** Nothing more will happen to these. */
    public const CLOSED_STATUSES = ['closed', 'cancelled'];

    /** Seconds in one unit of each rate basis. A month is 30 days, as quoted. */
    public const BASIS_SECONDS = [
        'hourly' => 3600,
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
    ];

    protected $fillable = [
        'rental_number', 'customer_id', 'warehouse_id', 'status', 'rate_basis',
        'booked_from', 'due_at', 'issued_at', 'returned_at', 'grace_hours',
        'id_proof_type', 'id_proof_number',
        'deposit_amount', 'deposit_applied', 'deposit_refunded',
        'hire_total', 'late_total', 'damage_total',
        'discount_type', 'discount_value', 'discount_amount',
        'tax_amount', 'round_off', 'total',
        'sales_order_id', 'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'booked_from' => 'datetime',
        'due_at' => 'datetime',
        'issued_at' => 'datetime',
        'returned_at' => 'datetime',
        'grace_hours' => 'integer',
        'deposit_amount' => 'decimal:2',
        'deposit_applied' => 'decimal:2',
        'deposit_refunded' => 'decimal:2',
        'hire_total' => 'decimal:2',
        'late_total' => 'decimal:2',
        'damage_total' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'booked',
        'rate_basis' => 'daily',
        'grace_hours' => 0,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RentalItem::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The moment the hire is charged from — when it left, not when it was booked. */
    public function chargeFrom(): Carbon
    {
        return $this->issued_at ?? $this->booked_from ?? $this->created_at ?? now();
    }

    /** Due time plus whatever grace was agreed. Lateness is measured from here. */
    public function graceUntil(): Carbon
    {
        return $this->due_at->copy()->addHours((int) $this->grace_hours);
    }

    /**
     * Out, and past the grace. Derived at the instant it is asked, so the board
     * cannot be stale — see the class note.
     */
    public function isOverdue(?Carbon $at = null): bool
    {
        if ($this->status !== 'out') {
            return false;
        }

        return ($at ?? now())->gt($this->graceUntil());
    }

    /** Hours late, negative while there is still time. Null unless it is out. */
    public function hoursLate(?Carbon $at = null): ?float
    {
        if ($this->status !== 'out') {
            return null;
        }

        return round($this->graceUntil()->diffInMinutes($at ?? now(), false) / 60, 2);
    }

    /** Lines still with the customer. */
    public function outstandingItems()
    {
        return $this->items->whereNull('returned_at');
    }

    /** Has every line come back? */
    public function isFullyReturned(): bool
    {
        return $this->items->isNotEmpty() && $this->outstandingItems()->isEmpty();
    }

    /** Charges before discount and tax — what settlement prices from. */
    public function chargeSubtotal(): float
    {
        return round(
            (float) $this->hire_total + (float) $this->late_total + (float) $this->damage_total,
            2,
        );
    }

    /**
     * What is left of the deposit once the bill has taken its share.
     *
     * Reported before settlement as well as after, because "how much do I get
     * back?" is asked at the counter while the kit is still being looked over,
     * and answering it from a stored column that has not been written yet is how
     * a customer gets told the wrong number.
     */
    public function depositBalance(): float
    {
        return round((float) $this->deposit_amount - (float) $this->deposit_applied, 2);
    }

    public function isBilled(): bool
    {
        return $this->invoice_id !== null;
    }

    /** Contracts holding kit over a window — the availability question. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', self::HOLDING_STATUSES);
    }

    /** Out and past grace, in SQL. */
    public function scopeOverdue(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->where('status', 'out')
            ->whereRaw('DATE_ADD(due_at, INTERVAL grace_hours HOUR) < ?', [$at]);
    }
}
