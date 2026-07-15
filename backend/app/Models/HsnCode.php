<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;

class HsnCode extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'type', 'description', 'gst_rate', 'cess_rate', 'uqc', 'is_active',
    ];

    protected $casts = [
        'gst_rate' => 'decimal:2',
        'cess_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
