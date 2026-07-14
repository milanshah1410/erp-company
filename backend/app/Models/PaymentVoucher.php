<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single payment movement — money in (customer receipt) or out
 * (supplier/vendor payout, standalone expense). Distributed across open
 * documents via {@see PaymentAllocation}; the unallocated remainder is the
 * party's advance / on-account balance.
 */
class PaymentVoucher extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'payment_number_prefix';

    /** Payment instruments. Cash/Bank/UPI/Cheque/Credit + adjustment (write-off/set-off). */
    public const MODES = ['cash', 'bank', 'upi', 'cheque', 'credit', 'adjustment'];

    /** Party aliases accepted from the API, mapped to their model class. */
    public const PARTY_TYPES = [
        'customer' => Customer::class,
        'supplier' => Supplier::class,
        'embroidery_vendor' => EmbroideryVendor::class,
        'tailor' => Tailor::class,
    ];

    /** Documents a voucher can be allocated against, keyed by API alias. */
    public const ALLOCATABLE_TYPES = [
        'invoice' => Invoice::class,
        'purchase_invoice' => PurchaseInvoice::class,
    ];

    protected $fillable = [
        'voucher_number', 'direction', 'party_type', 'party_id',
        'amount', 'allocated_amount', 'mode',
        'reference_no', 'bank_name', 'cheque_no', 'cheque_date', 'cheque_status',
        'payment_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'payment_date' => 'date',
        'cheque_date' => 'date',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Amount not yet applied to any document — the party's advance / credit. */
    public function unallocatedAmount(): float
    {
        return round((float) $this->amount - (float) $this->allocated_amount, 2);
    }

    /** True when part of this voucher is still sitting as an advance. */
    public function isAdvance(): bool
    {
        return $this->unallocatedAmount() > 0.005;
    }

    /** The short API alias for the stored party class, e.g. "customer". */
    public function partyAlias(): ?string
    {
        return $this->party_type
            ? array_search($this->party_type, self::PARTY_TYPES, true) ?: null
            : null;
    }
}
