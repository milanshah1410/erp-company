<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something came back under warranty.
 *
 * A claim is its own record here, unlike in the Automobile register where a
 * claim *is* a job card and counting them was enough. A footwear or watch shop
 * opens no job card to settle one: it takes the item in, someone looks at it,
 * and the answer is repair, replace, refund or no. That decision — and what it
 * cost the shop to honour — is the only trace the episode ever leaves, so it
 * needs somewhere to live.
 *
 * The money is not made here. A replacement goes out as an ordinary sale and a
 * refund as an ordinary credit note; `settlement_invoice_id` links to whichever
 * was raised. `cost` is the shop's own reckoning of what the episode cost it,
 * which is a management number, not a posting.
 */
class WarrantyClaim extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'product_warranty_claims';

    protected static string $numberSettingKey = 'warranty_claim_number_prefix';

    /** Outcomes that consume the covered item, so the card cannot be claimed again. */
    public const CONSUMING_RESOLUTIONS = ['replace', 'refund'];

    protected $fillable = [
        'claim_number', 'warranty_id', 'customer_id', 'reported_on', 'fault',
        'status', 'resolution', 'resolution_note', 'resolved_on',
        'cost', 'settlement_invoice_id', 'handled_by', 'created_by',
    ];

    protected $casts = [
        'reported_on' => 'date',
        'resolved_on' => 'date',
        'cost' => 'decimal:2',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'cost' => 0,
    ];

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(ProductWarranty::class, 'warranty_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The replacement bill or credit note, where one was raised. */
    public function settlementInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'settlement_invoice_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Is this claim finished, whichever way it went? */
    public function isClosed(): bool
    {
        return in_array($this->status, ['rejected', 'resolved'], true);
    }

    /**
     * Did settling this claim use the covered item up? Replacing or refunding
     * does: the original pair is gone, and the card that covered it goes with
     * it. Repairing does not — the same item is handed back and can fail again
     * inside the same term.
     */
    public function consumesCover(): bool
    {
        return in_array($this->resolution, self::CONSUMING_RESOLUTIONS, true);
    }
}
