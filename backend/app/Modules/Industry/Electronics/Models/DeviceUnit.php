<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Models\ServiceContract;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical machine — the record an electronics shop actually works against.
 *
 * Two customers buy the same 55" television and from that moment only the serial
 * number tells them apart: which one is still covered, which one has been opened
 * twice, which one the extended plan was sold against. So the unit is the master
 * record and the customer hangs off it, exactly as the vehicle is in a workshop.
 *
 * Deliberately not the core `serial_numbers` table. That one is an inventory
 * device — which serial is in which warehouse, what document moved it — and is
 * consumed by stock. This is the aftersales record, and it outlives the stock
 * movement that created it: a unit sold four years ago is still on this register,
 * still repairable, still argued about, long after its stock row stopped mattering.
 *
 * Identity is loose at the edges on purpose. A television has a serial, a phone
 * has one or two IMEIs, a ceiling fan has nothing at all until the shop writes a
 * sticker on it — and any one of those is enough to open a job card.
 */
class DeviceUnit extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'electronics_units';

    protected $fillable = [
        'customer_id', 'product_id', 'brand', 'model_name',
        'serial_no', 'imei_1', 'imei_2',
        'status', 'is_third_party',
        'sale_invoice_id', 'sold_at', 'delivered_at',
        'installation_required', 'installed_at',
        'warranty_months', 'amc_contract_id', 'last_service_at',
        'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_third_party' => 'boolean',
        'sold_at' => 'date',
        'delivered_at' => 'date',
        'installation_required' => 'boolean',
        'installed_at' => 'date',
        'warranty_months' => 'integer',
        'last_service_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults, so the predicates below read the same
     * values on a freshly built instance that they would after a reload. An
     * in-memory null `installation_required` would make awaitsInstallation()
     * answer false by accident rather than by decision.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'in_stock',
        'is_third_party' => false,
        'installation_required' => false,
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

    /** The AMC this unit is on, if one was sold. Core's contract, not a copy. */
    public function amcContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'amc_contract_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The warranty cards issued against this machine.
     *
     * Core's register, not one of this plugin's own — `product_warranties` was
     * already the place a claim is settled, and the plugin only added the
     * `unit_id` column that lets a card name a machine instead of a typed serial.
     * Declared here rather than on ProductWarranty so the dependency points from
     * the plugin at core; the reverse relation is registered at boot.
     */
    public function warranties(): HasMany
    {
        return $this->hasMany(ProductWarranty::class, 'unit_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'unit_id');
    }

    /**
     * Every job card ever raised on this unit — the service history.
     *
     * Declared here rather than on ServiceJob so the dependency points from the
     * plugin at the core and never back; the reverse relation is registered at
     * boot by ElectronicsPlugin, which is the one place core is allowed to learn
     * that units exist.
     */
    public function serviceJobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'device_unit_id');
    }

    /** The identifier the counter would read out — IMEI first for a handset. */
    public function identifier(): string
    {
        return $this->imei_1 ?: ($this->serial_no ?: "#{$this->id}");
    }

    /** "358240051111110 — Samsung Galaxy M35" for pickers and job-card headers. */
    public function label(): string
    {
        $description = trim(implode(' ', array_filter([$this->brand, $this->model_name])));

        return $description !== ''
            ? "{$this->identifier()} — {$description}"
            : $this->identifier();
    }

    /**
     * Sold, needs installing, nobody has been yet.
     *
     * Derived rather than stored as a status, because it is the answer to a
     * question the shop asks constantly ("what is outstanding for the fitters?")
     * and a stored state is only ever as accurate as the last person who
     * remembered to move it. The gap between delivery and installation is the
     * commonest complaint in this trade; it should not depend on data hygiene.
     */
    public function awaitsInstallation(): bool
    {
        return $this->installation_required
            && $this->installed_at === null
            && $this->status === 'sold';
    }

    /**
     * Stamp the machine as installed. Idempotent — a second visit to the same
     * unit does not rewrite the date it was first commissioned, because that
     * date is what warranty is counted from on most appliance covers.
     */
    public function markInstalled(?string $on = null): bool
    {
        if ($this->installed_at !== null) {
            return false;
        }

        $this->forceFill(['installed_at' => $on ?? now()->toDateString()])->save();

        return true;
    }

    /**
     * Record that work was done on this unit.
     *
     * Only ever moves forward: job cards are sometimes back-dated during a
     * catch-up entry session, and letting an older completion overwrite a newer
     * one would make "last serviced" read earlier than the last visit.
     */
    public function recordService(?\Illuminate\Support\Carbon $at = null): void
    {
        $at ??= now();

        if ($this->last_service_at && $this->last_service_at->gte($at)) {
            return;
        }

        $this->forceFill(['last_service_at' => $at])->save();
    }
}
