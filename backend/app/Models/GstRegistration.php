<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GstRegistration extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'company_id', 'branch_id', 'gstin', 'legal_name', 'trade_name',
        'state_code', 'state', 'registration_type', 'address',
        'effective_from', 'is_primary', 'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
