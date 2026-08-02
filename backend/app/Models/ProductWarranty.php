<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A warranty card: this item, sold on this bill, covered until this date.
 *
 * One clock only. That is the whole difference from VehicleWarranty, which runs
 * on time *and* distance because motor cover is written that way — a pair of
 * shoes has no odometer, and pretending otherwise would put a field on the form
 * that nobody at a footwear counter can answer.
 *
 * The item is identified by product *and* variant. For most trades the variant
 * is null and the product is the whole answer; for footwear and apparel it is
 * the only answer that means anything, because "Sparx SM-01" is not what failed
 * — the black size 8 is.
 */
class ProductWarranty extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'product_warranty_number_prefix';

    protected $fillable = [
        'warranty_number', 'customer_id', 'product_id', 'variant_id', 'serial_no',
        'invoice_id', 'source', 'start_date', 'end_date', 'coverage_months',
        'status', 'claims_count', 'last_claim_at', 'terms', 'notes', 'created_by',
        /**
         * Industry-plugin columns, added by Electronics & Appliances. Meaningless
         * to a footwear counter — a shoe is not installed and there is no unit
         * register behind it — but fillable here so a card can be written through
         * the ordinary create path rather than force-filled around the model.
         */
        'unit_id', 'covers_parts', 'covers_labour', 'is_onsite',
        /**
         * The same idea from the Mobile vertical, which keeps its own aftersales
         * register: `handset_id` gives a card an IMEI-identified phone rather than
         * only a typed-in serial, which is what makes "is this covered?" answerable
         * from a scanner. A second column rather than reusing `unit_id` because the
         * two point at different tables, and a shop that sells phones and repairs
         * appliances runs both verticals at once.
         */
        'handset_id',
        /**
         * The Optical & Eyewear vertical's column, on the same principle: a
         * spectacle order is not billed like an ordinary sale and never passed
         * the counter's "register this warranty?" step, so cover is issued when
         * the pair is handed over and this is what ties the card back to it. A
         * pair yields two cards — the frame's and the lens's — because breakage
         * and coating peel are two different promises with two different terms.
         */
        'optical_order_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'coverage_months' => 'integer',
        'claims_count' => 'integer',
        'last_claim_at' => 'datetime',
        'covers_parts' => 'boolean',
        'covers_labour' => 'boolean',
        'is_onsite' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults, so covers() reads the same on a
     * freshly-made instance as it does after a reload. A null status would make
     * covers() pass by accident rather than by decision, and the answer decides
     * whether the shop or the customer pays.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'manufacturer',
        'status' => 'active',
        'claims_count' => 0,
        // Mirrors of the plugin columns' defaults, for the same reason: a card
        // built in memory must report the same coverage it will report after a
        // reload, or coverageLabel() reads "nothing" on a brand-new manufacturer
        // warranty.
        'covers_parts' => true,
        'covers_labour' => true,
        'is_onsite' => false,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The size/colour actually handed over, where the trade has variants. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'warranty_id');
    }

    /**
     * Is this cover in force on the given day?
     *
     * `void` and `expired` are refused outright, and so is `claimed` — which is
     * where this differs from motor cover. A claimed card here means the covered
     * item was replaced or refunded, so there is nothing left to cover; honouring
     * it twice would give away a second pair for one failure.
     */
    public function covers(?Carbon $on = null): bool
    {
        if (in_array($this->status, ['void', 'expired', 'claimed'], true)) {
            return false;
        }

        $on ??= now();

        return ! $on->lt($this->start_date) && ! $on->gt($this->end_date->endOfDay());
    }

    /**
     * Days of cover left, never negative — "0 left" is the honest reading of an
     * exhausted warranty, and a negative number invites arithmetic nobody meant.
     */
    public function daysRemaining(?Carbon $on = null): int
    {
        $on ??= now();

        return $this->end_date && $this->end_date->endOfDay()->isAfter($on)
            ? (int) $on->startOfDay()->diffInDays($this->end_date)
            : 0;
    }

    /**
     * What the item is, as a counter would say it — "Sparx SM-01 · Black / 8".
     * The variant name is appended rather than replacing the product name,
     * because a variant called "Black / 8" tells nobody which shoe it is.
     */
    public function itemLabel(): string
    {
        $parts = array_filter([
            $this->product?->name,
            $this->variant?->name ?: $this->variant?->sku,
            $this->serial_no ? "#{$this->serial_no}" : null,
        ]);

        return implode(' · ', $parts) ?: '—';
    }

    /**
     * What this cover pays for, in the words the counter would use.
     *
     * Deliberately a sentence rather than two booleans on screen: "parts only" is
     * the phrase that has to be said out loud to a customer who believes the
     * whole visit is free, and the shop should not be assembling it themselves.
     *
     * Named coverageLabel and not scopeLabel because Eloquent reads a `scope`
     * prefix as a local query scope.
     */
    public function coverageLabel(): string
    {
        return match (true) {
            $this->covers_parts && $this->covers_labour => 'Parts & labour',
            (bool) $this->covers_parts => 'Parts only',
            (bool) $this->covers_labour => 'Labour only',
            default => 'Documentary only — nothing is paid for',
        };
    }

    /**
     * Why cover did not apply, in the words the counter would use. Returned
     * alongside the refusal so the shop can say "it ran out in March" instead of
     * "no cover found", which reads to a customer as a lost record.
     */
    public function refusalReason(?Carbon $on = null): ?string
    {
        $on ??= now();

        if ($this->covers($on)) {
            return null;
        }

        return match (true) {
            $this->status === 'void' => 'This cover was cancelled.',
            $this->status === 'claimed' => 'Already claimed — the item was replaced or refunded.',
            $this->start_date && $on->lt($this->start_date) => 'Does not start until '.$this->start_date->format('d M Y').'.',
            (bool) $this->end_date => 'Expired on '.$this->end_date->format('d M Y').'.',
            default => 'Not currently in force.',
        };
    }

    /** Stamp a claim against the cover. Called when a claim is registered. */
    public function recordClaim(): void
    {
        $this->forceFill([
            'claims_count' => (int) $this->claims_count + 1,
            'last_claim_at' => now(),
        ])->save();
    }
}
