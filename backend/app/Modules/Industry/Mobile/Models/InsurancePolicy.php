<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\User;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Damage and theft insurance on a handset — the cover a warranty is not.
 *
 * A warranty promises the phone was not faulty. Insurance pays when the customer
 * drops it, drowns it or has it stolen, which is the opposite kind of event: it is
 * written by an underwriter, sold for a premium the shop earns commission on,
 * carries an excess the customer pays per claim, and — the part that settles
 * arguments — is normally finished after one claim.
 *
 * That last point is why this is not a fourth `source` on the core warranty card.
 * A warranty card can be claimed against repeatedly for unrelated faults; this
 * cover is *used up*, and a register that did not know the difference would send a
 * customer away certain of a second screen replacement that nobody is going to
 * fund.
 *
 * Claims are not a table. A claim *is* a repair job card
 * (service_jobs.insurance_policy_id) — the shop replaces the screen and recovers
 * from the insurer, it does not open a second document — so claims_count and
 * last_claim_at exist to let the book be read without opening the job book.
 */
class InsurancePolicy extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'mobile_insurance_policies';

    protected static string $numberSettingKey = 'insurance_policy_number_prefix';

    protected $fillable = [
        'policy_number', 'provider', 'provider_policy_no',
        'customer_id', 'handset_id', 'product_id', 'imei', 'invoice_id',
        'start_date', 'end_date', 'coverage_months',
        'premium', 'sum_insured', 'deductible',
        'covers_accidental_damage', 'covers_liquid_damage', 'covers_theft',
        'claim_limit', 'status', 'claims_count', 'last_claim_at',
        'terms', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'coverage_months' => 'integer',
        'premium' => 'decimal:2',
        'sum_insured' => 'decimal:2',
        'deductible' => 'decimal:2',
        'covers_accidental_damage' => 'boolean',
        'covers_liquid_damage' => 'boolean',
        'covers_theft' => 'boolean',
        'claim_limit' => 'integer',
        'claims_count' => 'integer',
        'last_claim_at' => 'datetime',
    ];

    /**
     * Mirrors of the column defaults, so covers() reads the same values on a freshly
     * created instance that it would after a reload. Left to Eloquent, `status` is
     * null in memory and covers() passes by accident rather than by decision — not
     * a thing to leave to luck when the answer decides who pays for a screen.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'claims_count' => 0,
        'premium' => 0,
        'deductible' => 0,
        'claim_limit' => 1,
        'covers_accidental_damage' => true,
        'covers_liquid_damage' => false,
        'covers_theft' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $policy) {
            $policy->imei = Imei::normalize($policy->imei);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function handset(): BelongsTo
    {
        return $this->belongsTo(Handset::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** What the premium was billed on, so it traces back to the money. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Repairs worked under this policy — the claim history. */
    public function claims(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'insurance_policy_id');
    }

    /** Match on either handle: the linked handset, or the bare IMEI. */
    public function scopeForImei(Builder $query, string $imei): Builder
    {
        $digits = Imei::normalize($imei) ?? $imei;

        return $query->where(function (Builder $q) use ($digits) {
            $q->where('imei', $digits)
                ->orWhereHas('handset', fn (Builder $h) => $h->identifier($digits));
        });
    }

    /**
     * Does this policy cover a loss today?
     *
     * `void` and `expired` are refused outright, and so is an exhausted policy —
     * which is the whole difference between this and a warranty card. The date
     * window is checked last because it is the answer a customer is least
     * surprised by.
     */
    public function covers(?Carbon $on = null): bool
    {
        if (in_array($this->status, ['void', 'expired'], true)) {
            return false;
        }

        if ($this->isExhausted()) {
            return false;
        }

        $on ??= now();

        return ! ($on->lt($this->start_date) || $on->gt($this->end_date->endOfDay()));
    }

    /** Has the policy's allowance of claims been used up? */
    public function isExhausted(): bool
    {
        return $this->claim_limit !== null
            && (int) $this->claims_count >= (int) $this->claim_limit;
    }

    /** Claims still available, or null on a policy with no stated limit. */
    public function claimsRemaining(): ?int
    {
        if ($this->claim_limit === null) {
            return null;
        }

        return max(0, (int) $this->claim_limit - (int) $this->claims_count);
    }

    /** Whole days of cover left; 0 once it has run out. */
    public function daysRemaining(): int
    {
        if (! $this->end_date || $this->end_date->isPast()) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($this->end_date);
    }

    /**
     * Stamp a claim. Called when a repair job first names this policy.
     *
     * Reaching the limit moves the row to `claimed`, which is the honest reading of
     * a used-up policy — but the state is derived from the count rather than a
     * substitute for it, so covers() still checks the count itself.
     */
    public function recordClaim(): void
    {
        $count = (int) $this->claims_count + 1;

        $this->forceFill([
            'claims_count' => $count,
            'last_claim_at' => now(),
            'status' => $this->claim_limit !== null && $count >= (int) $this->claim_limit
                ? 'claimed'
                : $this->status,
        ])->save();
    }

    /** The perils, in the words the counter uses. @return list<string> */
    public function perils(): array
    {
        $out = [];

        if ($this->covers_accidental_damage) {
            $out[] = 'Accidental damage';
        }
        if ($this->covers_liquid_damage) {
            $out[] = 'Liquid damage';
        }
        if ($this->covers_theft) {
            $out[] = 'Theft';
        }

        return $out;
    }
}
