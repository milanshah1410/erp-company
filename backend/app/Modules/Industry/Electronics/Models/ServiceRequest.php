<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The desk: everything a customer rings about, before it is work.
 *
 * An installation, a delivery, a demo, an AMC visit and a fault all arrive the
 * same way — someone calls — and only the outcome differs. A repair and an
 * installation become job cards; a delivery and a demo are usually just done; a
 * complaint is often answered on the phone. Opening a job card per call would
 * leave the worksheet full of work that nobody ever performed, and the shop
 * would lose the one number this desk exists to produce: how many calls came in
 * and how many were actually attended.
 *
 * So converting is an explicit act, and afterwards the two rows stay linked in
 * both directions.
 *
 * `under_warranty` is a snapshot, not a live lookup, and that is the point: it
 * records what the desk *told the customer* on the day. A promise made in good
 * faith on Monday must not read as a mistake on Friday because the cover lapsed
 * in between.
 */
class ServiceRequest extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'electronics_service_requests';

    protected static string $numberSettingKey = 'service_request_number_prefix';

    /** Types that become a job card rather than simply being done and closed. */
    public const JOB_TYPES = ['installation', 'repair', 'amc_visit'];

    /** Statuses the desk can still act on. */
    public const OPEN_STATUSES = ['logged', 'scheduled', 'assigned'];

    protected $fillable = [
        'request_number', 'type', 'source', 'status', 'priority',
        'unit_id', 'customer_id', 'product_id',
        'scheduled_at', 'promised_at', 'assigned_to', 'warehouse_id',
        'is_onsite', 'address', 'contact_phone',
        'complaint', 'estimated_cost',
        'under_warranty', 'warranty_id', 'service_job_id',
        'reminder_sent_at', 'closed_at', 'closure_note',
        'notes', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'promised_at' => 'datetime',
        'is_onsite' => 'boolean',
        'estimated_cost' => 'decimal:2',
        'under_warranty' => 'boolean',
        'reminder_sent_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'repair',
        'source' => 'phone',
        'status' => 'logged',
        'priority' => 'normal',
        'is_onsite' => true,
        'under_warranty' => false,
        'estimated_cost' => 0,
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(DeviceUnit::class, 'unit_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The core warranty card the desk found when the call was taken. */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(ProductWarranty::class, 'warranty_id');
    }

    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Is the desk still expecting to do something about this? */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Would this request ordinarily produce a job card?
     *
     * A delivery and a demo would not: there is nothing to diagnose, no part to
     * issue and nothing to bill separately, so a job card for them is paperwork
     * with no work behind it. They are closed off the desk instead.
     */
    public function needsJobCard(): bool
    {
        return in_array($this->type, self::JOB_TYPES, true);
    }

    /**
     * Late — promised for a time that has passed, and not yet attended.
     *
     * Promised beats scheduled here: `scheduled_at` is when the shop intends to
     * go, `promised_at` is what the customer was told, and only the second one
     * is a broken promise when it slips.
     */
    public function isOverdue(): bool
    {
        $due = $this->promised_at ?? $this->scheduled_at;

        return $this->isOpen() && $due !== null && $due->isPast();
    }

    /** Close a request that was finished without a job card behind it. */
    public function close(?string $note = null): void
    {
        $this->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closure_note' => $note,
        ])->save();
    }
}
