<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Department;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ward's request on the central store — the hospital's internal stock document.
 *
 * Nothing here is sold. The ICU asks for gloves, the store approves what it can
 * spare, and the goods move from the store's warehouse to the ward's consumption.
 * That is a stock movement with a cost centre attached, which is why this
 * document deliberately does not touch the sales spine: routing it through a
 * sales order would raise a receivable against a department that cannot pay it,
 * and every ageing report in the system would be wrong.
 *
 * The department is the reason the document exists at all. A plain stock
 * adjustment can move the same gloves out of the same warehouse — it just cannot
 * say who used them, and "who used them" is the only question a hospital store
 * is ever asked.
 */
class HospitalIndent extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'hospital_indent_number_prefix';

    protected $fillable = [
        'indent_number', 'department_id', 'warehouse_id',
        'indent_date', 'required_by', 'priority', 'status',
        'requested_by', 'approved_by', 'approved_at', 'issued_at',
        'rejection_reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'indent_date' => 'date',
        'required_by' => 'date',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(HospitalIndentItem::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * May the lines still be rewritten?
     *
     * Only before the store has agreed to anything. Once approved, the lines are
     * what the store committed to release, and once something has been issued
     * they describe goods that have physically left — see the immutable-lines
     * rule the controller enforces.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'submitted'], true);
    }

    public function isIssuable(): bool
    {
        return in_array($this->status, ['approved', 'partially_issued'], true);
    }

    /**
     * Recompute the header from what has actually been issued.
     *
     * Called after every issue rather than set by the button that issued: with
     * partial issues the state is a property of the lines, and deriving it is
     * the only way "issued" can never be claimed while a line is still short.
     */
    public function refreshIssueStatus(): void
    {
        $this->loadMissing('items');

        $issuedSomething = $this->items->contains(fn (HospitalIndentItem $i) => (float) $i->issued_quantity > 0);
        $allDone = $this->items->every(fn (HospitalIndentItem $i) => $i->isFullyIssued());

        $status = match (true) {
            $allDone && $issuedSomething => 'issued',
            $issuedSomething => 'partially_issued',
            default => $this->status,
        };

        $this->forceFill([
            'status' => $status,
            'issued_at' => $status === 'issued' ? ($this->issued_at ?? now()) : $this->issued_at,
        ])->save();
    }
}
