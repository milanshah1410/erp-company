<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A date a customer buys a gift for.
 *
 * Not the customer's own birthday — core already holds that on `customers` and
 * tells the counter about it on the morning. This register holds the dates a gift
 * shop actually sells against, which differ from core's in the two ways that
 * matter:
 *
 *  1. **Whose date it is.** People buy gifts for a wife, a mother, a son's
 *     teacher, a boss. Core cannot hold those, and a shop that only knows its
 *     customers' own birthdays knows almost none of its occasions.
 *  2. **When to say something.** A gift has to be chosen and is often ordered, so
 *     a reminder on the day is a reminder after the money was spent somewhere
 *     else. Hence `remind_days_before`, defaulting to a week.
 *
 * Everything about *when* is derived rather than stored. A recurring occasion
 * keeps the original date (the year someone was born or married) and the next
 * occurrence is computed on read — a stored "next date" needs a nightly job to
 * stay true, and is wrong for every day between that job failing and someone
 * noticing. The one thing that is stored is `last_reminded_on`, because it
 * records what was actually done.
 */
class GiftOccasion extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    public const OCCASIONS = [
        'birthday', 'anniversary', 'wedding', 'festival',
        'graduation', 'housewarming', 'other',
    ];

    protected $fillable = [
        'customer_id', 'person_name', 'relation', 'occasion', 'title',
        'occasion_date', 'is_recurring', 'remind_days_before',
        'budget', 'preferences', 'last_gift', 'last_gifted_on',
        'notify_customer', 'last_reminded_on', 'source',
        'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'occasion_date' => 'date',
        'is_recurring' => 'boolean',
        'remind_days_before' => 'integer',
        'budget' => 'decimal:2',
        'last_gifted_on' => 'date',
        'notify_customer' => 'boolean',
        'last_reminded_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'occasion' => 'birthday',
        'is_recurring' => true,
        'remind_days_before' => 7,
        'notify_customer' => true,
        'source' => 'manual',
        'is_active' => true,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Who the gift is for, as the counter would say it. */
    public function personLabel(): string
    {
        $name = trim((string) $this->person_name);

        if ($name === '') {
            return $this->customer?->name ?? 'Customer';
        }

        return $this->relation ? $name.' ('.$this->relation.')' : $name;
    }

    /** What the occasion is called, falling back to the kind of thing it is. */
    public function occasionLabel(): string
    {
        return trim((string) $this->title) !== ''
            ? (string) $this->title
            : ucfirst(str_replace('_', ' ', (string) $this->occasion));
    }

    /**
     * The next time this comes round, from a given day.
     *
     * A recurring occasion rolls to this year and then to next year once it has
     * passed; a one-off (a wedding, a graduation) simply is its date, and returns
     * it even once it is in the past — a lapsed one-off should drop off the board
     * rather than reappear a year later as a wedding that is not happening.
     *
     * 29 February is handled by Carbon's own overflow: setting the year on a leap
     * date lands on 1 March in a common year, which is the same answer a shop
     * gives.
     */
    public function nextOccurrence(?Carbon $from = null): ?Carbon
    {
        if (! $this->occasion_date) {
            return null;
        }

        $from = ($from ?? Carbon::today())->copy()->startOfDay();
        $date = $this->occasion_date->copy()->startOfDay();

        if (! $this->is_recurring) {
            return $date;
        }

        $next = $date->copy()->year($from->year);

        if ($next->lt($from)) {
            $next = $date->copy()->year($from->year + 1);
        }

        return $next;
    }

    /** Days until it comes round; negative for a one-off already gone. */
    public function daysUntil(?Carbon $from = null): ?int
    {
        $next = $this->nextOccurrence($from);

        if (! $next) {
            return null;
        }

        $from = ($from ?? Carbon::today())->copy()->startOfDay();

        return (int) $from->diffInDays($next, false);
    }

    /** The day the shop should be saying something. */
    public function remindOn(?Carbon $from = null): ?Carbon
    {
        return $this->nextOccurrence($from)?->copy()->subDays(max(0, (int) $this->remind_days_before));
    }

    /**
     * Is a reminder owed today?
     *
     * True from the lead-time day right up to the occasion itself — a shop that
     * was closed on the day the reminder fell due should still be told the
     * morning after, because there is still time to sell something. False once
     * this cycle has already been reminded, which is what `last_reminded_on`
     * exists for: a reminder sent on the 3rd is not owed again on the 4th.
     */
    public function reminderDue(?Carbon $from = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $days = $this->daysUntil($from);
        $next = $this->nextOccurrence($from);

        if ($days === null || $next === null || $days < 0) {
            return false;
        }

        if ($days > max(0, (int) $this->remind_days_before)) {
            return false;
        }

        // Already told them about *this* occurrence. Compared against the window
        // rather than "today" so a yearly occasion is reminded once a year even
        // though the row is never reset.
        return ! ($this->last_reminded_on
            && $this->last_reminded_on->gte($next->copy()->subDays(max(0, (int) $this->remind_days_before))));
    }
}
