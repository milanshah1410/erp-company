<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card somebody holds.
 *
 * `status` is not derived from the dates: a card can be cancelled mid-term — a
 * refund, a barred customer — and must stop entitling immediately while its
 * history stays readable. So entitlement asks both questions, which is what
 * `isCurrentlyValid()` is for; nothing should test the dates or the status alone.
 */
class CustomerMembership extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'customer_id', 'membership_plan_id', 'member_number', 'starts_on', 'ends_on',
        'status', 'price_paid', 'sales_order_id', 'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'price_paid' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Cards that entitle right now — marked active *and* inside their dates.
     *
     * The date test is here rather than left to a nightly job because a card
     * expiring at midnight must stop working at midnight, whether or not anything
     * has run since. The job that flips `status` to 'expired' is housekeeping for
     * the reports, not the thing entitlement depends on.
     */
    public function scopeEntitling(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('status', 'active')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today);
    }

    public function isCurrentlyValid(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $today = now()->startOfDay();

        return ! $today->lt($this->starts_on->startOfDay())
            && ! $today->gt($this->ends_on->startOfDay());
    }

    /** Days left on the card; negative once it has lapsed. */
    public function daysRemaining(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->ends_on->startOfDay(), false);
    }
}
