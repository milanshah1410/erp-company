<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A household's standing order: half a litre every morning, billed monthly.
 *
 * This is the *instruction*, not the sales history. What actually went through the
 * gate is one MilkSubscriptionDelivery row per day — including the days it did
 * not, because the family travelled or the boy did not come. The month is billed
 * from those rows and never from this record, which is the whole reason they are
 * separate: a month billed from the instruction is a month of arguments.
 *
 * `rate` is held here rather than read off the product each morning. A household
 * on an old rate stays on it until the dairy tells them otherwise — that is what
 * a subscription is.
 */
class MilkSubscription extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'milk_subscription_number_prefix';

    protected $fillable = [
        'subscription_number', 'customer_id', 'product_id', 'variant_id',
        'quantity_per_day', 'rate', 'shift', 'days', 'delivery_route_id',
        'start_date', 'end_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'quantity_per_day' => 'decimal:3',
        'rate' => 'decimal:2',
        'days' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'delivery_route_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MilkSubscriptionDelivery::class, 'milk_subscription_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Is milk due on this date?
     *
     * Asks the three questions the round sheet asks: is the order live, has it
     * started (and not ended), and is today one of its days. A paused
     * subscription answers no without losing its place — the family comes back to
     * the same standing order, rate and balance.
     */
    public function isDueOn(CarbonInterface $date): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($date->lt($this->start_date->startOfDay())) {
            return false;
        }

        if ($this->end_date && $date->gt($this->end_date->endOfDay())) {
            return false;
        }

        $days = array_map('intval', $this->days ?? []);

        // An empty day list means every day — a subscription with no days would
        // otherwise silently never deliver.
        return $days === [] || in_array($date->isoWeekday(), $days, true);
    }

    /**
     * The shifts a day's delivery covers.
     *
     * 'both' is one subscription that goes twice, not two subscriptions, because
     * that is how the household thinks of it and how the round is walked.
     *
     * @return list<string>
     */
    public function shifts(): array
    {
        return $this->shift === 'both' ? ['morning', 'evening'] : [$this->shift];
    }
}
