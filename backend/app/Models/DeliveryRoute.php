<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named delivery route (area / start–end points, distance, default freight
 * charge). Referenced by dispatch plans to standardise recurring deliveries.
 */
class DeliveryRoute extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'area', 'start_point', 'end_point',
        'distance_km', 'estimated_hours', 'default_charge', 'is_active',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'estimated_hours' => 'decimal:2',
        'default_charge' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function dispatchPlans(): HasMany
    {
        return $this->hasMany(DispatchPlan::class, 'route_id');
    }
}
