<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmbroideryWork extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'embroidery_work_number_prefix';

    protected $fillable = [
        'work_number', 'embroidery_vendor_id', 'product_id', 'product_variant_id',
        'design_number', 'stitch_count', 'dispatched_qty', 'received_qty', 'cost',
        'status', 'dispatch_date', 'due_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'stitch_count' => 'integer',
        'dispatched_qty' => 'decimal:2',
        'received_qty' => 'decimal:2',
        'cost' => 'decimal:2',
        'dispatch_date' => 'date',
        'due_date' => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(EmbroideryVendor::class, 'embroidery_vendor_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(EmbroideryReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Pieces still out at the vendor (dispatched but not yet received). */
    public function pendingQty(): float
    {
        return round((float) $this->dispatched_qty - (float) $this->received_qty, 2);
    }

    /** Recompute pending/partial/completed from received vs dispatched qty. */
    public function refreshStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $received = (float) $this->received_qty;
        $this->status = $received <= 0
            ? 'pending'
            : ($received + 0.005 >= (float) $this->dispatched_qty ? 'completed' : 'partial');
        $this->save();
    }
}
