<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job-work order sent to an outside vendor (a Supplier). Material is issued
 * (stock out), processed by the vendor, and finished goods are received back
 * (stock in). Each item reconciles issued vs received vs damage vs shortage;
 * the remainder is pending at the vendor.
 *
 * Lifecycle: draft → issued → partial → completed (or closed / cancelled).
 */
class JobWork extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'jobwork_number_prefix';

    protected $fillable = [
        'job_work_number', 'supplier_id', 'warehouse_id', 'status',
        'job_type', 'job_work_date', 'expected_date', 'issued_date', 'completed_date',
        'notes', 'created_by', 'issued_by', 'closed_by',
    ];

    protected $casts = [
        'job_work_date' => 'date',
        'expected_date' => 'date',
        'issued_date' => 'date',
        'completed_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(JobWorkItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Recompute status from the item rollups after a receive/close. Draft,
     * cancelled and closed are terminal for this helper — it only moves an
     * issued order between issued → partial → completed.
     */
    public function refreshStatus(): void
    {
        if (in_array($this->status, ['draft', 'cancelled', 'closed'], true)) {
            return;
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        $anyAccounted = $items->contains(
            fn ($i) => (float) $i->received_qty + (float) $i->damage_qty + (float) $i->shortage_qty > 0
        );
        $allComplete = $items->every(fn ($i) => (float) $i->pending_qty <= 0);

        if ($allComplete) {
            $this->status = 'completed';
            $this->completed_date = $this->completed_date ?? now()->toDateString();
        } elseif ($anyAccounted) {
            $this->status = 'partial';
        } else {
            $this->status = 'issued';
        }

        $this->save();
    }
}
