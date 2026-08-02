<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an EMI schedule.
 *
 * Three states and not four: "overdue" is `pending` with a due date in the past. It
 * is derived on every read rather than stored, because the transition happens at
 * midnight with nobody watching, and a stored flag is wrong from then until
 * whenever a sweep runs.
 */
class EmiInstalment extends Model
{
    use BelongsToTenant;

    protected $table = 'mobile_emi_instalments';

    protected $fillable = [
        'emi_plan_id', 'sequence', 'due_date', 'amount',
        'status', 'paid_on', 'paid_amount', 'payment_id', 'notes',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_on' => 'date',
        'paid_amount' => 'decimal:2',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'paid_amount' => 0,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(EmiPlan::class, 'emi_plan_id');
    }

    /** The receipt this collection wrote — in-house files only. */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->isPast();
    }

    /** Days late; 0 for anything not actually late. */
    public function daysLate(): int
    {
        return $this->isOverdue()
            ? (int) $this->due_date->startOfDay()->diffInDays(now()->startOfDay())
            : 0;
    }

    /** Pending and past its date — the collections list. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->whereDate('due_date', '<', now()->toDateString());
    }

    /** Pending and falling due inside the window — the follow-up list. */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->where('status', 'pending')
            ->whereBetween('due_date', [
                now()->toDateString(),
                now()->addDays($days)->toDateString(),
            ]);
    }
}
