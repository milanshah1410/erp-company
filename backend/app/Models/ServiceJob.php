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

    protected $fillable = [
        'job_number', 'customer_id', 'service_contract_id', 'warehouse_id', 'assigned_to',
        'status', 'priority', 'item_name', 'item_serial',
        'complaint', 'diagnosis', 'resolution', 'is_warranty',
        'received_at', 'promised_at', 'started_at', 'completed_at', 'delivered_at',
        'labour_charge', 'labour_tax_percent', 'parts_total',
        'discount_type', 'discount_value', 'discount_amount',
        'tax_amount', 'round_off', 'total',
        'sales_order_id', 'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_warranty' => 'boolean',
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
