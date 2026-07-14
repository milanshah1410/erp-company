<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'code', 'name', 'annual_quota', 'is_paid', 'carry_forward', 'description', 'is_active',
    ];

    protected $casts = [
        'annual_quota' => 'decimal:1',
        'is_paid' => 'boolean',
        'carry_forward' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }
}
