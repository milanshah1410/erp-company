<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Transporter master — the inland haulage contractor that moves cargo between
 * port / CFS / ICD and the factory or warehouse. Distinct from `vehicles` and
 * `drivers`, which model the tenant's own delivery fleet.
 */
class Transporter extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** How the transporter carries the cargo. */
    public const MODES = ['road', 'rail', 'multimodal'];

    protected $fillable = [
        'code', 'name', 'contact_person', 'phone', 'email', 'gst_number',
        'pan_number', 'transport_mode', 'vehicle_types', 'address', 'city', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
