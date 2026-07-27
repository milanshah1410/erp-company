<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Airline (air carrier) master. `awb_prefix` is the carrier's 3-digit
 * air-waybill prefix, used when numbering air shipments.
 */
class Airline extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'icao_code', 'name', 'awb_prefix', 'contact_person', 'phone',
        'email', 'website', 'address', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
