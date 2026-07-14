<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricDevice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'code', 'name', 'location', 'serial_no', 'ip_address', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function punches(): HasMany
    {
        return $this->hasMany(BiometricPunch::class);
    }
}
