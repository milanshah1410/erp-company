<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An annual maintenance contract. Jobs booked against a live contract are done
 * but not billed — the customer paid for the term up front — and each one draws
 * down the visit quota.
 */
class ServiceContract extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'service_contract_number_prefix';

    protected $fillable = [
        'contract_number', 'customer_id', 'title', 'start_date', 'end_date',
        'visits_allowed', 'visits_used', 'amount', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'visits_allowed' => 'integer',
        'visits_used' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class);
    }

    /** Visits still available, or null when the contract sells unlimited ones. */
    public function visitsRemaining(): ?int
    {
        return $this->visits_allowed > 0
            ? max(0, $this->visits_allowed - $this->visits_used)
            : null;
    }

    /**
     * Can a job be booked against this contract today?
     *
     * Checked at booking, not at completion: a contract that lapses mid-repair
     * still covers the job it was booked under.
     */
    public function coversToday(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->end_date->isPast() || $this->start_date->isFuture()) {
            return false;
        }

        return $this->visitsRemaining() === null || $this->visitsRemaining() > 0;
    }
}
