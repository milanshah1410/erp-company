<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Support\TradeCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trade document on a shipment — the Pending Documents board (SRS §24).
 * A new shipment is seeded with the mandatory checklist for its leg
 * (TradeCatalogue::mandatoryDocumentsFor) in 'pending' state, so the desk sees
 * what is still missing from day one. The scanned copy lives on the tenant's
 * private disk and is served only through short-lived signed URLs.
 */
class ShipmentDocument extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Statuses that still need someone to act. */
    public const OPEN_STATUSES = ['pending', 'submitted', 'rejected'];

    protected $fillable = [
        'shipment_id', 'doc_type', 'doc_number', 'doc_date', 'due_date',
        'status', 'is_mandatory', 'file_path', 'remarks', 'uploaded_by',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'due_date' => 'date',
        'is_mandatory' => 'boolean',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Documents still outstanding (anything not received or verified). */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function label(): string
    {
        return TradeCatalogue::documentLabel($this->doc_type);
    }

    /** Past its due date and still outstanding. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && in_array($this->status, self::OPEN_STATUSES, true)
            && $this->due_date->isPast();
    }
}
