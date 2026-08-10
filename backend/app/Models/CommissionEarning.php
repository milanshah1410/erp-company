<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one member of staff earned on one line of one sale (retail base §8b).
 *
 * A stored row rather than a live calculation, and the reason is worth keeping
 * in view: rules get edited, prices get corrected, and cost gets restated by the
 * next stock movement. A commission figure that quietly changes after somebody
 * has been told what they earned is worse than no figure at all.
 */
class CommissionEarning extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** States in which the money has not left yet, and may still be corrected. */
    public const OPEN_STATUSES = ['pending', 'approved'];

    protected $fillable = [
        'employee_id', 'commission_rule_id',
        'sales_order_id', 'invoice_id', 'sales_item_id', 'product_id',
        'earned_on', 'period_year', 'period_month',
        'quantity', 'sale_amount', 'cost_amount', 'margin_amount',
        'basis', 'rate', 'commission_amount',
        'status', 'approved_by', 'approved_at', 'paid_on', 'payment_reference', 'notes',
    ];

    protected $casts = [
        'earned_on' => 'date',
        'period_year' => 'integer',
        'period_month' => 'integer',
        'quantity' => 'decimal:3',
        'sale_amount' => 'decimal:2',
        'cost_amount' => 'decimal:2',
        'margin_amount' => 'decimal:2',
        'rate' => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_on' => 'date',
    ];

    /** Paid money is settled: it can be reversed but never edited or re-approved. */
    public function isSettled(): bool
    {
        return $this->status === 'paid';
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }

    /** Reversals are excluded from every total — they exist to cancel, not to count. */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->where('status', '!=', 'reversed');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesItem(): BelongsTo
    {
        return $this->belongsTo(SalesItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
