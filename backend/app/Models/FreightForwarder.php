<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Freight forwarder master — the agent that books space with the carrier and
 * consolidates cargo. Billed through the shipment's freight & expense lines.
 */
class FreightForwarder extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'contact_person', 'phone', 'email', 'gst_number',
        'pan_number', 'country_id', 'address', 'city', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
