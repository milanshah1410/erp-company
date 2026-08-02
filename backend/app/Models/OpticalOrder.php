<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spectacle order: frame + lenses, ground to the powers on it.
 *
 * Lifecycle: draft → ordered → at_lab → received → fitted → delivered, with
 * cancelled available until it is billed. The powers are copied onto the order
 * rather than read through to the prescription, so a later re-test cannot
 * restate what was actually made.
 */
class OpticalOrder extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'optical_order_number_prefix';

    /** Statuses that no longer accept edits or billing. */
    public const CLOSED_STATUSES = ['delivered', 'cancelled'];

    /** The order the workshop moves through, for validating a transition. */
    public const FLOW = ['draft', 'ordered', 'at_lab', 'received', 'fitted', 'delivered'];

    protected $fillable = [
        'order_number', 'customer_id', 'eye_prescription_id', 'warehouse_id',
        'frame_product_id', 'lens_product_id', 'status', 'vision_type',
        'right_sph', 'right_cyl', 'right_axis', 'right_add',
        'left_sph', 'left_cyl', 'left_axis', 'left_add', 'pd',
        'frame_price', 'lens_price', 'fitting_charge',
        'discount_type', 'discount_value', 'discount_amount',
        'tax_percent', 'tax_amount', 'round_off', 'total',
        'promised_on', 'ordered_at', 'received_at', 'delivered_at',
        'sales_order_id', 'invoice_id', 'lab_notes', 'notes', 'created_by',
        /**
         * Collection, added by the Optical & Eyewear plugin. `fitted_at` is the
         * moment the pair became collectable — the clock the desk is judged on,
         * which the three timestamps above never captured — and the rest is who
         * was told, how it left the shop and who actually took it.
         *
         * Written by the collection desk and by OpticalOrderObserver, not by the
         * order form, but fillable here so the columns are reachable through the
         * ordinary model.
         */
        'fitted_at', 'notified_at', 'notify_count',
        'delivery_mode', 'delivery_address', 'delivery_reference',
        'collected_by_name', 'collected_by_phone', 'handover_notes', 'warranty_issued_at',
    ];

    protected $casts = [
        'right_sph' => 'decimal:2',
        'right_cyl' => 'decimal:2',
        'right_axis' => 'integer',
        'right_add' => 'decimal:2',
        'left_sph' => 'decimal:2',
        'left_cyl' => 'decimal:2',
        'left_axis' => 'integer',
        'left_add' => 'decimal:2',
        'pd' => 'decimal:1',
        'frame_price' => 'decimal:2',
        'lens_price' => 'decimal:2',
        'fitting_charge' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
        'promised_on' => 'date',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
        'delivered_at' => 'datetime',
        // Optical & Eyewear plugin: the collection clocks.
        'fitted_at' => 'datetime',
        'notified_at' => 'datetime',
        'notify_count' => 'integer',
        'warranty_issued_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(EyePrescription::class, 'eye_prescription_id');
    }

    public function frame(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'frame_product_id');
    }

    public function lens(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'lens_product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /** Past its promised date and not yet handed over. */
    public function isOverdue(): bool
    {
        return $this->promised_on !== null
            && $this->promised_on->isPast()
            && ! in_array($this->status, ['delivered', 'cancelled'], true);
    }

    /**
     * May the order move to this status?
     *
     * Forward-only along the workshop flow. Going back would mean the lenses
     * un-arrived, which is not a thing that happens — a mistake is corrected by
     * cancelling and re-ordering, and that leaves a trail.
     */
    public function canMoveTo(string $status): bool
    {
        if ($status === 'cancelled') {
            return ! $this->isClosed();
        }

        $from = array_search($this->status, self::FLOW, true);
        $to = array_search($status, self::FLOW, true);

        return $from !== false && $to !== false && $to > $from;
    }
}
