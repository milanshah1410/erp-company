<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One slice of a {@see PaymentVoucher} applied to an open document
 * (sales / purchase invoice). The sum of a voucher's allocations equals its
 * allocated_amount; whatever is left over is the party's advance balance.
 */
class PaymentAllocation extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'payment_voucher_id', 'allocatable_type', 'allocatable_id', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The short API alias for the stored target class, e.g. "invoice". */
    public function allocatableAlias(): ?string
    {
        return array_search($this->allocatable_type, PaymentVoucher::ALLOCATABLE_TYPES, true) ?: null;
    }
}
