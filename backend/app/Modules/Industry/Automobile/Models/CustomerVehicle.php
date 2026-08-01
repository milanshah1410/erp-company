<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer's vehicle — the master record a workshop actually works against.
 *
 * Named CustomerVehicle rather than Vehicle because App\Models\Vehicle already
 * means something else and always will: the company's *own* fleet, which fuel
 * bills, dispatch plans and driver assignments hang off. The two must never
 * merge — a customer's Swift has no business appearing in the dispatch
 * planner's vehicle picker, and the shop's delivery van has no service history
 * to bill anyone for.
 *
 * Identity is deliberately loose at the edges. A vehicle may arrive with a VIN
 * and no plate (unregistered, straight off the truck) or a plate and no VIN
 * (nobody wanted to crawl under a fifteen-year-old hatchback), so both are
 * nullable and either alone is enough to open a job card.
 */
class CustomerVehicle extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'automobile_vehicles';

    protected $fillable = [
        'customer_id', 'registration_no', 'vin', 'engine_no',
        'make', 'model', 'variant', 'manufacture_year',
        'fuel_type', 'transmission', 'colour',
        'odometer', 'odometer_updated_at',
        'registration_date', 'delivery_date', 'sale_invoice_id',
        'insurance_provider', 'insurance_policy_no', 'insurance_expiry',
        'puc_expiry', 'fitness_expiry',
        'service_interval_days', 'service_interval_km',
        'last_service_at', 'next_service_due_at', 'next_service_odometer',
        'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'odometer' => 'integer',
        'odometer_updated_at' => 'datetime',
        'manufacture_year' => 'integer',
        'registration_date' => 'date',
        'delivery_date' => 'date',
        'insurance_expiry' => 'date',
        'puc_expiry' => 'date',
        'fitness_expiry' => 'date',
        'service_interval_days' => 'integer',
        'service_interval_km' => 'integer',
        'last_service_at' => 'datetime',
        'next_service_due_at' => 'date',
        'next_service_odometer' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults. recordOdometer() compares against the
     * in-memory reading, and a null one would make the first comparison a
     * null-vs-int test rather than the "never go backwards" rule it is meant
     * to be.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'odometer' => 0,
        'is_active' => true,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'sale_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VehicleBooking::class, 'vehicle_id');
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(VehicleWarranty::class, 'vehicle_id');
    }

    /**
     * Every job card ever raised on this vehicle — the service history.
     *
     * Declared here rather than on ServiceJob so the dependency points from the
     * plugin at the core and never back; the reverse relation is registered at
     * boot by AutomobilePlugin, which is the one place core is allowed to learn
     * that vehicles exist.
     */
    public function serviceJobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'vehicle_id');
    }

    /** "GJ01AB1234 — Maruti Swift VDi" for pickers and job-card headers. */
    public function label(): string
    {
        $identity = $this->registration_no ?: ($this->vin ?: "#{$this->id}");
        $description = trim(implode(' ', array_filter([$this->make, $this->model, $this->variant])));

        return $description !== '' ? "{$identity} — {$description}" : $identity;
    }

    /**
     * Record a reading, and only ever forwards.
     *
     * Odometers do not run backwards, so a lower number is either a typo at the
     * gate or a different vehicle — and letting it through would corrupt both
     * the service schedule and every distance-limited warranty on the vehicle.
     * Refusing silently is right here: the caller is stamping a reading as a
     * side effect of some larger act (opening a job card, taking a booking), and
     * failing that act over a mistyped digit helps nobody.
     */
    public function recordOdometer(?int $reading): bool
    {
        if ($reading === null || $reading <= (int) $this->odometer) {
            return false;
        }

        $this->forceFill([
            'odometer' => $reading,
            'odometer_updated_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Re-derive the next service due from this vehicle's own schedule.
     *
     * Both limbs are honoured because manufacturers state both and the workshop
     * wants whichever comes first — a taxi hits the distance long before the
     * date, a second car the other way round.
     */
    public function scheduleNextService(?Carbon $servicedAt = null, ?int $atOdometer = null): void
    {
        $servicedAt ??= now();
        $atOdometer ??= (int) $this->odometer;

        $this->forceFill([
            'last_service_at' => $servicedAt,
            'next_service_due_at' => $this->service_interval_days
                ? $servicedAt->copy()->addDays((int) $this->service_interval_days)->toDateString()
                : null,
            'next_service_odometer' => $this->service_interval_km
                ? $atOdometer + (int) $this->service_interval_km
                : null,
        ])->save();
    }

    /**
     * Is a service due — by date, by distance, or both?
     *
     * `$withinDays` widens the date limb so a shop can call tomorrow's work
     * today; the distance limb has no equivalent slack because a vehicle can
     * cover a service interval's worth of kilometres in a week and there is no
     * useful "nearly there" for it.
     */
    public function isServiceDue(int $withinDays = 0): bool
    {
        $byDate = $this->next_service_due_at
            && $this->next_service_due_at->lte(now()->addDays($withinDays));

        $byOdometer = $this->next_service_odometer
            && (int) $this->odometer >= (int) $this->next_service_odometer;

        return $byDate || $byOdometer;
    }

    /**
     * Papers that have expired or are about to, as label => date.
     *
     * A garage is where an owner finds out the PUC ran out in March, so this is
     * on the vehicle rather than in a report nobody opens.
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    public function expiringDocuments(int $withinDays = 30): array
    {
        $limit = now()->addDays($withinDays);

        return array_filter([
            'insurance' => $this->insurance_expiry,
            'puc' => $this->puc_expiry,
            'fitness' => $this->fitness_expiry,
        ], static fn (?Carbon $date) => $date !== null && $date->lte($limit));
    }
}
