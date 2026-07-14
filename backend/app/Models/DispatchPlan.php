<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dispatch plan — schedules a vehicle + driver + route to deliver a set of
 * consignments (dispatch items) to customers.
 *
 * Lifecycle: draft → dispatched → delivered, with cancel available while draft.
 * "delivered" is reached once all consignments have Proof of Delivery recorded
 * (or explicitly via the deliver action).
 */
class DispatchPlan extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'dispatch_number_prefix';

    protected $fillable = [
        'dispatch_number', 'dispatch_date', 'vehicle_id', 'driver_id', 'route_id',
        'status', 'remarks', 'dispatched_at', 'delivered_at', 'created_by',
    ];

    protected $casts = [
        'dispatch_date' => 'date',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DispatchItem::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(TransportCharge::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
