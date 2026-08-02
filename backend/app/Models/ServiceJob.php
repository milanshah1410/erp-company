<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repair / installation job on a customer's item.
 *
 * Lifecycle: received → assigned → in_progress → completed → delivered, with
 * on_hold and cancelled available throughout. Parts are issued from stock as
 * the technician fits them; billing happens once at completion and goes out
 * through an ordinary Sales Order + Invoice on the 'service' channel.
 *
 * A job under warranty or an active AMC is worked and closed without any
 * document — `isBillable()` is the single place that decides.
 */
class ServiceJob extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'service_job_number_prefix';

    /** Statuses that no longer accept work — parts, assignment or billing. */
    public const CLOSED_STATUSES = ['delivered', 'cancelled'];

    /**
     * Relations an industry plugin has attached to this model, name => name.
     *
     * A vertical that extends the job with its own record — Automobile puts a
     * vehicle on it, Electronics a serial-numbered unit — registers the relation
     * at boot with resolveRelationUsing and lists it here, so the controller
     * knows what is safe to eager-load. The indirection is the point: with those
     * plugins unregistered the list is empty, the columns are unused nullables,
     * and nothing here has to know a vehicle was ever a possibility.
     *
     * Plugins prefix their relation names (`deviceWarranty`, not `warranty`)
     * because two verticals can be registered in one workspace and the second to
     * boot would otherwise silently replace the first's relation.
     *
     * @var array<string, string>
     */
    public static array $pluginRelations = [];

    protected $fillable = [
        'job_number', 'customer_id', 'service_contract_id', 'warehouse_id', 'assigned_to',
        'status', 'priority', 'item_name', 'item_serial',
        'complaint', 'diagnosis', 'resolution', 'is_warranty',
        // Industry-plugin columns. Nullable and meaningless to most repair
        // trades — an air conditioner has no odometer — but fillable here so a
        // vertical can open a job through the ordinary create path rather than
        // force-filling around the model.
        'vehicle_id', 'booking_id', 'warranty_id', 'odometer_in', 'odometer_out',
        'device_unit_id', 'service_request_id', 'device_warranty_id',
        'it_asset_id', 'it_service_call_id', 'it_warranty_id',
        'handset_id', 'insurance_policy_id',
        // Sports: a job card against the shop's own hire fleet rather than a
        // customer's item — the bike that came back with a buckled wheel.
        'sports_asset_id',
        'received_at', 'promised_at', 'started_at', 'completed_at', 'delivered_at',
        'labour_charge', 'labour_tax_percent', 'parts_total',
        'discount_type', 'discount_value', 'discount_amount',
        'tax_amount', 'round_off', 'total',
        'sales_order_id', 'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_warranty' => 'boolean',
        'odometer_in' => 'integer',
        'odometer_out' => 'integer',
        'received_at' => 'datetime',
        'promised_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'labour_charge' => 'decimal:2',
        'labour_tax_percent' => 'decimal:2',
        'parts_total' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'service_contract_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceJobItem::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Is the job past the point where its lines or assignment may change? */
    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Should this job produce an invoice?
     *
     * Warranty and contract work is real work that the customer does not pay
     * for again, and a zero-value job (all parts free, no labour) has nothing
     * to bill either.
     */
    public function isBillable(): bool
    {
        return ! $this->is_warranty
            && ! $this->service_contract_id
            && (float) $this->total > 0;
    }
}
