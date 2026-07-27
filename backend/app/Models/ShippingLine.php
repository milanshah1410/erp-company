<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Shipping line (ocean carrier) master. The SCAC code is the carrier code that
 * appears on the Bill of Lading.
 */
class ShippingLine extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'scac_code', 'contact_person', 'phone', 'email',
        'website', 'address', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
