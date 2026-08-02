<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What is still covered on a machine, until when, and at what service level.
 *
 * IT cover runs on one clock, not two — there is no odometer on a laptop — so
 * the interesting limb is not distance but **who travels**. "Three years"
 * describes nothing on its own: three years carry-in is the customer driving
 * over with the machine, three years next-business-day on-site is an engineer at
 * their desk within a day, and the two are sold at very different prices. A
 * register that stores only dates ends up promising the expensive one.
 *
 * Claims are not a table. A claim *is* a job card worked under this cover
 * (service_jobs.it_warranty_id), so a claims table would be an emptier copy of
 * work that already exists; `claims_count` and `last_claim_at` are here so the
 * register reads without touching the job book.
 */
class AssetWarranty extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'computer_it_warranties';

    protected static string $numberSettingKey = 'it_warranty_number_prefix';

    /** Cover that lets an engineer be sent to the customer's premises. */
    public const ONSITE_LEVELS = ['onsite', 'nbd_onsite'];

    protected $fillable = [
        'warranty_number', 'type', 'provider',
        'asset_id', 'customer_id', 'product_id', 'serial_no',
        'invoice_id', 'service_job_id',
        'start_date', 'end_date', 'coverage_months', 'service_level',
        'status', 'claims_count', 'last_claim_at',
        'terms', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'coverage_months' => 'integer',
        'claims_count' => 'integer',
        'last_claim_at' => 'datetime',
    ];

    /**
     * Mirrors of the column defaults, so covers() reads the same values on a
     * freshly created instance that it would after a reload. An in-memory null
     * status makes covers() pass by accident rather than by decision, which is
     * not a thing to leave to luck when the answer decides who pays for a repair.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'product',
        'provider' => 'manufacturer',
        'service_level' => 'carry_in',
        'status' => 'active',
        'claims_count' => 0,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'asset_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The part, for a part warranty. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** What it was sold on, so a claim traces back to the money. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The repair this cover was issued against, for a service warranty. */
    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Job cards worked under this cover — the claim history. */
    public function claims(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'it_warranty_id');
    }

    /**
     * Does this cover a repair on the given day?
     *
     * `void` and `expired` are refused outright; `claimed` is not, because
     * claiming once does not use a warranty up — a laptop under manufacturer
     * cover can come back for a second, unrelated fault, and treating the first
     * claim as terminal would bill the customer for work they are owed.
     */
    public function covers(?Carbon $on = null): bool
    {
        if (in_array($this->status, ['void', 'expired'], true)) {
            return false;
        }

        $on ??= now();

        return ! $on->lt($this->start_date) && ! $on->gt($this->end_date->endOfDay());
    }

    /** Days left on the cover. Never negative — "0 left" is the honest reading. */
    public function daysRemaining(?Carbon $on = null): int
    {
        $on ??= now();

        return $this->end_date->endOfDay()->isAfter($on)
            ? (int) $on->startOfDay()->diffInDays($this->end_date)
            : 0;
    }

    /** Does this cover pay for an engineer to travel, or only for bench work? */
    public function coversOnsite(): bool
    {
        return in_array($this->service_level, self::ONSITE_LEVELS, true);
    }

    /** Stamp a claim against the cover. Called when a job card is opened on it. */
    public function recordClaim(): void
    {
        $this->forceFill([
            'claims_count' => (int) $this->claims_count + 1,
            'last_claim_at' => now(),
            // Deliberately not moved to a terminal 'claimed' state — see
            // covers(). The register shows the count; the cover stays live until
            // its end date passes.
            'status' => $this->status === 'active' ? 'active' : $this->status,
        ])->save();
    }
}
