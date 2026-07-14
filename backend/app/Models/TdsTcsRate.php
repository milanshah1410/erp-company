<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * A TDS or TCS statutory section and its rate (e.g. 194C @ 1%, 206C(1H) @ 0.1%).
 * Referenced by section code on purchase invoices / expenses (TDS) and sales
 * orders (TCS); the amounts themselves are stored on those documents.
 */
class TdsTcsRate extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'section', 'nature', 'rate', 'threshold', 'description', 'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'threshold' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
