<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Category;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A time-window price rule: 30% off beverages, weekdays 16:00–19:00.
 *
 * The rule is a clock, so it is evaluated while the ticket is being priced rather
 * than switched on and off by hand — a manager who has to remember to enable the
 * offer at four is a manager who will forget.
 *
 * Everything about *whether* an offer applies lives on this model, and nothing
 * about it lives in the caller. That is deliberate: the same question is asked
 * from the restaurant ticket, from POS and from the public QR menu, and three
 * copies of "is it Tuesday between four and seven" would drift apart.
 */
class MenuHappyHour extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'name', 'days', 'start_time', 'end_time', 'valid_from', 'valid_to',
        'scope', 'category_id', 'discount_type', 'discount_value',
        'applies_to', 'priority', 'is_active', 'created_by',
    ];

    protected $casts = [
        'days' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'discount_value' => 'decimal:2',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Products the offer covers when `scope` is 'products'. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'menu_happy_hour_items',
            'menu_happy_hour_id',
            'product_id',
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Active offers, best-priority first — the order the evaluator wants them in. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderByDesc('priority')->orderBy('id');
    }

    /**
     * Is this offer running at the given moment?
     *
     * Three tests, cheapest first: the date range, the day of the week, then the
     * clock. The clock test handles a window that crosses midnight — a bar
     * running 22:00 to 01:00 is one offer, and splitting it into two rows would
     * mean the shop no longer sees back the hours it typed.
     */
    public function isLiveAt(CarbonInterface $at): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from && $at->lt($this->valid_from->startOfDay())) {
            return false;
        }

        if ($this->valid_to && $at->gt($this->valid_to->endOfDay())) {
            return false;
        }

        $days = array_map('intval', $this->days ?? []);
        if ($days !== [] && ! in_array($at->isoWeekday(), $days, true)) {
            return false;
        }

        return $this->clockMatches($at);
    }

    /**
     * Is the offer honoured on this kind of order?
     *
     * A café discounting its own counter rarely means the same price to go out on
     * an aggregator delivery, and that distinction is the difference between an
     * offer and a loss. Restaurant order types are dine_in / takeaway / delivery;
     * anything unrecognised (a POS counter sale) is treated as dine-in, which is
     * what walking up to the till is.
     */
    public function appliesToOrderType(?string $orderType): bool
    {
        if ($this->applies_to === 'all') {
            return true;
        }

        return $this->applies_to === ($orderType ?: 'dine_in');
    }

    /**
     * Is this dish covered?
     *
     * `$categoryId` is passed in rather than read off the product so the caller
     * can resolve every line's category in one query instead of one per line —
     * this is called for every item on every ticket.
     */
    public function coversProduct(int $productId, ?int $categoryId, array $scopedProductIds = []): bool
    {
        return match ($this->scope) {
            'all' => true,
            'category' => $this->category_id !== null && $categoryId === (int) $this->category_id,
            'products' => in_array($productId, $scopedProductIds, true),
            default => false,
        };
    }

    /**
     * The discount on one line, in rupees.
     *
     * Capped at the line value: a ₹50-off offer on a ₹40 coffee gives the coffee
     * away, it does not hand ₹10 back.
     */
    public function discountFor(float $lineValue): float
    {
        if ($lineValue <= 0) {
            return 0.0;
        }

        $discount = $this->discount_type === 'percent'
            ? $lineValue * (float) $this->discount_value / 100
            : (float) $this->discount_value;

        return round(min($discount, $lineValue), 2);
    }

    /**
     * The clock test, midnight wrap included.
     *
     * A window whose end is at or before its start is read as crossing midnight,
     * so 22:00–01:00 matches 23:30 and 00:30 but not 12:00.
     */
    private function clockMatches(CarbonInterface $at): bool
    {
        $now = $at->format('H:i:s');
        $start = $this->normalizeTime($this->start_time);
        $end = $this->normalizeTime($this->end_time);

        if ($start === $end) {
            // A zero-length window is a misconfiguration, not an all-day offer.
            return false;
        }

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);
    }

    /** Times come back from MySQL as 'HH:MM:SS' but may be keyed as 'HH:MM'. */
    private function normalizeTime(mixed $value): string
    {
        $text = (string) $value;

        return match (strlen($text)) {
            5 => $text.':00',
            8 => $text,
            default => substr($text.':00:00', 0, 8),
        };
    }
}
