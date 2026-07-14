<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TailorWork extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'tailor_work_number_prefix';

    protected $fillable = [
        'work_number', 'tailor_id', 'product_id', 'product_variant_id', 'rate_card_id',
        'issued_qty', 'completed_qty', 'rejected_qty', 'scrapped_qty', 'rate', 'status',
        'issue_date', 'due_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'issued_qty' => 'decimal:2',
        'completed_qty' => 'decimal:2',
        'rejected_qty' => 'decimal:2',
        'scrapped_qty' => 'decimal:2',
        'rate' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
    ];

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(Tailor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(TailorWorkCompletion::class);
    }

    public function alterations(): HasMany
    {
        return $this->hasMany(TailorAlteration::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Pieces still to be produced: issued minus the accepted (completed) and the
     * scrapped pieces. Reworkable rejects are intentionally NOT subtracted — they
     * stay in this pool for the tailor to redo (and get paid on acceptance).
     */
    public function remainingQty(): float
    {
        return round((float) $this->issued_qty - (float) $this->completed_qty - (float) $this->scrapped_qty, 2);
    }

    /**
     * Recompute pending/partial/completed. The order is complete once every
     * issued piece is resolved — accepted or scrapped.
     */
    public function refreshStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $resolved = (float) $this->completed_qty + (float) $this->scrapped_qty;
        $this->status = $resolved <= 0
            ? 'pending'
            : ($resolved + 0.005 >= (float) $this->issued_qty ? 'completed' : 'partial');
        $this->save();
    }
}
