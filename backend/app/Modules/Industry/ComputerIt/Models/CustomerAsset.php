<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ServiceContract;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One machine on a customer's desk — the install base a computer shop works
 * against for years after the sale.
 *
 * Named CustomerAsset rather than Asset because App\Models\Asset already means
 * something else and always will: the company's *own* fixed-asset register, what
 * depreciation and internal maintenance are booked against. The two must never
 * merge — a customer's laptop has no business on the shop's balance sheet, and
 * the shop's own server has no service history to bill anyone for.
 *
 * It is also deliberately not the core `serial_numbers` table. That one is an
 * inventory device — which serial sits in which warehouse, which document moved
 * it — and is spent by the stock movement that issues it. This record starts
 * where that one ends: a machine sold four years ago is still on this register,
 * still under an AMC, still argued about.
 *
 * Identity is loose at the edges on purpose. A branded laptop has a serial, an
 * assembled desktop has nothing at all until the shop puts a sticker on it, and
 * either is enough to open a job card — so `asset_tag` is generated and
 * `serial_no` is nullable.
 */
class CustomerAsset extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'computer_it_assets';

    /** Feeds nextNumber(); the tag is generated, never typed. */
    protected static string $numberSettingKey = 'it_asset_tag_prefix';

    protected $fillable = [
        'asset_tag', 'customer_id', 'product_id',
        'category', 'make', 'model', 'serial_no', 'specification', 'os', 'mac_address',
        'site', 'department', 'assigned_user',
        'purchase_date', 'installed_on', 'sale_invoice_id', 'amc_contract_id',
        'status', 'service_interval_days', 'last_service_at', 'next_service_due_at',
        'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'installed_on' => 'date',
        'service_interval_days' => 'integer',
        'last_service_at' => 'datetime',
        'next_service_due_at' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults, so a freshly created instance reads the
     * same as a reloaded one. Without them a machine created through the
     * conversion path has a null status in memory and the "put it back on the
     * desk" step below refuses to fire on a record it just made.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'category' => 'laptop',
        'status' => 'deployed',
        'is_active' => true,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'sale_invoice_id');
    }

    /** The AMC this machine is on — a core service contract, not a plugin table. */
    public function amcContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'amc_contract_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(AssetWarranty::class, 'asset_id');
    }

    public function licenceKeys(): HasMany
    {
        return $this->hasMany(LicenceKey::class, 'asset_id');
    }

    public function serviceCalls(): HasMany
    {
        return $this->hasMany(ServiceCall::class, 'asset_id');
    }

    /**
     * Every job card ever raised on this machine — its repair history.
     *
     * Declared here rather than on ServiceJob so the dependency points from the
     * plugin at the core and never back; the reverse relation is registered at
     * boot by ComputerItPlugin, which is the one place core is allowed to learn
     * that customer machines exist.
     */
    public function serviceJobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'it_asset_id');
    }

    /** "AST-000012 — Dell Latitude 3520 (SN 9XKLM23)" for pickers and job cards. */
    public function label(): string
    {
        $description = trim(implode(' ', array_filter([$this->make, $this->model])));
        $identity = $description !== '' ? $description : $this->asset_tag;

        return $this->serial_no ? "{$identity} · {$this->serial_no}" : $identity;
    }

    /**
     * Cover that is live today, preferring the manufacturer's over anything the
     * shop itself gave.
     *
     * Order matters for money: a fault inside the manufacturer's warranty is the
     * manufacturer's to fix, and claiming it against the shop's own repair
     * warranty instead puts the cost on the wrong party. Ties beyond that go to
     * whichever cover ends soonest, so the one about to lapse gets used while it
     * still can be.
     */
    public function liveWarranty(?Carbon $on = null): ?AssetWarranty
    {
        return $this->warranties()
            ->whereIn('status', ['active', 'claimed'])
            ->orderByRaw("CASE WHEN provider = 'manufacturer' THEN 0 ELSE 1 END")
            ->orderBy('end_date')
            ->get()
            ->first(fn (AssetWarranty $w) => $w->covers($on));
    }

    /** Is this machine on an AMC that still has a visit left in it today? */
    public function amcCoversToday(): bool
    {
        return (bool) $this->amcContract?->coversToday();
    }

    /**
     * Stamp a service and re-derive when the next one is due.
     *
     * Only the date limb exists here — a laptop has no odometer — so a machine
     * with no interval set simply keeps a `last_service_at` and never appears on
     * the due list, which is the honest reading of "nobody promised this
     * customer preventive visits".
     */
    public function recordService(?Carbon $servicedAt = null): void
    {
        $servicedAt ??= now();

        $this->forceFill([
            'last_service_at' => $servicedAt,
            'next_service_due_at' => $this->service_interval_days
                ? $servicedAt->copy()->addDays((int) $this->service_interval_days)->toDateString()
                : null,
        ])->save();
    }

    /** Is a preventive visit due — today, or inside the next `$withinDays`? */
    public function isServiceDue(int $withinDays = 0): bool
    {
        return $this->next_service_due_at !== null
            && $this->next_service_due_at->lte(now()->addDays($withinDays));
    }

    /**
     * Move the machine's whereabouts as work starts and finishes.
     *
     * Only ever between `deployed` and `in_service`: a machine that has been
     * retired, or is sitting in the loaner pool, is in that state for a reason
     * that a repair does not overrule.
     */
    public function moveToStatus(string $status): bool
    {
        if (! in_array($this->status, ['deployed', 'in_service'], true)) {
            return false;
        }

        if ($this->status === $status) {
            return false;
        }

        $this->forceFill(['status' => $status])->save();

        return true;
    }
}
