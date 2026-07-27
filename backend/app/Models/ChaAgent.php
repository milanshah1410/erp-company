<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customs House Agent master — files the Bill of Entry (import) or Shipping
 * Bill (export) at its licensed port. License expiry is tracked so a lapsed
 * licence can be surfaced before a clearance is handed to the agent.
 */
class ChaAgent extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'license_no', 'license_expiry', 'port_id',
        'contact_person', 'phone', 'email', 'gst_number', 'address', 'is_active',
    ];

    protected $casts = [
        'license_expiry' => 'date',
        'is_active' => 'boolean',
    ];

    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }
}
