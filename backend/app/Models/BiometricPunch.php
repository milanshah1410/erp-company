<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricPunch extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'biometric_device_id', 'employee_id', 'device_user_id',
        'punched_at', 'direction', 'source', 'processed',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
        'processed' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
