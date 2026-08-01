<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Models;

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
 * What is still covered, and until when.
 *
 * A warranty in this trade runs on two clocks at once and ends when *either*
 * stops: three years or 45,000 km, whichever comes first. Both limbs are stored
 * and both are checked, because a register that tracks only dates hands free
 * clutch plates to taxis, and one that tracks only distance charges a
 * second-car owner for a fault that appeared in month nine.
 *
 * Claims are not a table. A claim *is* a job card worked under this cover
 * (service_jobs.warranty_id), so a claims table would be an emptier copy of
 * work that already exists; `claims_count` and `last_claim_at` are here so the
 * register reads without touching the job book.
 */
class VehicleWarranty extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'automobile_warranties';

    protected static string $numberSettingKey = 'warranty_number_prefix';

    protected $fillable = [
        'warranty_number', 'type', 'provider',
        'vehicle_id', 'customer_id', 'product_id', 'serial_no',
        'invoice_id', 'service_job_id',
        'start_date', 'end_date', 'coverage_months', 'coverage_km', 'start_odometer',
        'status', 'claims_count', 'last_claim_at',
        'terms', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'coverage_months' => 'integer',
        'coverage_km' => 'integer',
        'start_odometer' => 'integer',
        'claims_count' => 'integer',
        'last_claim_at' => 'datetime',
    ];

    /**
     * Mirrors of the column defaults, so covers() and recordClaim() read the
     * same values on a freshly created instance that they would after a reload.
     * An in-memory null status makes covers() pass by accident rather than by
     * decision, which is not a thing to leave to luck when the answer decides
     * who pays for a repair.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'vehicle',
        'provider' => 'manufacturer',
        'status' => 'active',
        'claims_count' => 0,
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class, 'vehicle_id');
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
        return $this->hasMany(ServiceJob::class, 'warranty_id');
    }

    /**
     * Does this cover a repair on the given day, at the given reading?
     *
     * `void` and `expired` are refused outright; `claimed` is not, because
     * claiming once does not use a warranty up — a car under manufacturer cover
     * can come back for a second, unrelated fault, and treating the first claim
     * as terminal would bill the customer for work they are owed.
     *
     * An unknown odometer skips the distance limb rather than failing it: the
     * shop simply did not take a reading, and refusing cover over a missing
     * number would deny a claim on a technicality of data entry.
     */
    public function covers(?Carbon $on = null, ?int $odometer = null): bool
    {
        if (in_array($this->status, ['void', 'expired'], true)) {
            return false;
        }

        $on ??= now();

        if ($on->lt($this->start_date) || $on->gt($this->end_date->endOfDay())) {
            return false;
        }

        if ($this->coverage_km && $odometer !== null) {
            $covered = (int) ($this->start_odometer ?? 0) + (int) $this->coverage_km;
            if ($odometer > $covered) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kilometres left on the distance limb, or null when there is no such limb.
     * Never negative: "0 left" is the honest reading of an exhausted warranty.
     */
    public function kmRemaining(?int $odometer = null): ?int
    {
        if (! $this->coverage_km) {
            return null;
        }

        $odometer ??= (int) ($this->vehicle?->odometer ?? 0);
        $covered = (int) ($this->start_odometer ?? 0) + (int) $this->coverage_km;

        return max(0, $covered - $odometer);
    }

    /** Stamp a claim against the cover. Called when a job card is opened on it. */
    public function recordClaim(): void
    {
        $this->forceFill([
            'claims_count' => (int) $this->claims_count + 1,
            'last_claim_at' => now(),
            // Deliberately not 'claimed' as a terminal state — see covers().
            // The register shows the count; the cover stays live until it runs
            // out on one of its two clocks.
            'status' => $this->status === 'active' ? 'active' : $this->status,
        ])->save();
    }
}
