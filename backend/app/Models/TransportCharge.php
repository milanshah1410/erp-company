<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A freight / logistics charge (freight, loading, toll, detention…), optionally
 * tied to a dispatch plan. `payer` + `payment_mode` capture whether the company
 * paid, it is to-pay, or it will be billed on to the customer.
 */
class TransportCharge extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'transport_charge_number_prefix';

    protected $fillable = [
        'charge_number', 'charge_date', 'dispatch_plan_id', 'vehicle_id', 'customer_id',
        'transporter_name', 'lr_number', 'charge_type', 'payment_mode', 'payer',
        'amount', 'remarks', 'created_by',
    ];

    protected $casts = [
        'charge_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function dispatchPlan(): BelongsTo
    {
        return $this->belongsTo(DispatchPlan::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
