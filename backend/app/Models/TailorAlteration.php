<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece (or set of pieces) sent back for adjustment after delivery. Optionally
 * tied to the originating work order and assigned to a tailor to redo the work.
 *
 * Serves two trades from one document. The workroom case is a piece coming back
 * off a production work order; the retail case is a customer who bought a
 * trouser and wants the hem taken up by Friday. They differ only in what the
 * alteration hangs off — `tailor_work_id` for one, `customer_id` / `invoice_id`
 * for the other — and everything else (the status flow, the numbering, the
 * tailor assignment, the charge) is identical, which is why a garment showroom
 * gets a second door onto this screen instead of a second alteration register.
 */
class TailorAlteration extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'tailor_alteration_number_prefix';

    protected $fillable = [
        'alteration_number', 'tailor_work_id', 'tailor_id', 'product_id',
        'qty', 'reason', 'status', 'charge',
        'reported_date', 'completed_date', 'notes', 'created_by',
        // The retail half: whose garment it is, the bill it was sold on, which
        // size/colour is on the hanger, and when it was promised.
        'customer_id', 'invoice_id', 'variant_id', 'promised_date',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'charge' => 'decimal:2',
        'reported_date' => 'date',
        'promised_date' => 'date',
        'completed_date' => 'date',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(TailorWork::class, 'tailor_work_id');
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(Tailor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Promised for a date already past, and still not done.
     *
     * Derived rather than stored: an alteration promised for yesterday is late
     * this morning whether or not anything has swept the table, and the counter
     * needs to know before the customer walks in for it.
     */
    public function isLate(): bool
    {
        return $this->status !== 'done'
            && $this->promised_date !== null
            && $this->promised_date->endOfDay()->isPast();
    }
}
