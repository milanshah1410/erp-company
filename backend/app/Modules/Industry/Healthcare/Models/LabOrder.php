<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Prescription;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient's investigation order, from registration to a released report.
 *
 * The status here is the *clinical* one — registered, collected, processing,
 * reported, delivered. Whether it has been paid for is a separate question that
 * `invoice_id` answers, because the two genuinely do not move together: a
 * walk-in pays before the sample is drawn, a corporate panel is reported first
 * and billed at month end, and a single combined status would misdescribe both.
 */
class LabOrder extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'lab_order_number_prefix';

    /** Statuses from which nothing further should be edited or billed. */
    public const CLOSED_STATUSES = ['delivered', 'cancelled'];

    protected $fillable = [
        'order_number', 'customer_id',
        'patient_name', 'patient_age', 'patient_gender', 'patient_phone',
        'referring_doctor', 'doctor_registration_no', 'prescription_id',
        'order_date', 'priority', 'status',
        'subtotal', 'item_discount_total', 'discount_type', 'discount_value',
        'discount_amount', 'tax_amount', 'round_off', 'total',
        'sales_order_id', 'invoice_id',
        'collected_at', 'reported_at', 'delivered_at',
        'verified_by', 'created_by', 'notes',
    ];

    protected $casts = [
        'patient_age' => 'integer',
        'order_date' => 'date',
        'subtotal' => 'decimal:2',
        'item_discount_total' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
        'collected_at' => 'datetime',
        'reported_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(LabOrderResult::class)->orderBy('sort_order')->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * May the tests on this order still be changed?
     *
     * Once a sample has been collected the order describes something physical
     * that already happened, and adding a test to it would claim the lab drew
     * blood for an investigation nobody had asked for at the time. Billing
     * closes it for the ordinary reason: the numbers are in the books.
     */
    public function isEditable(): bool
    {
        return $this->status === 'registered' && $this->invoice_id === null;
    }

    /** Every parameter has a value entered against it. */
    public function isFullyResulted(): bool
    {
        return ! $this->results()->where('flag', 'pending')->exists();
    }
}
