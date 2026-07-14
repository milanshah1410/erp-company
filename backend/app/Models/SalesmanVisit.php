<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesmanVisit extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'salesman_id', 'customer_id', 'beat_id', 'visit_date', 'sequence', 'status',
        'purpose', 'check_in_at', 'check_out_at', 'check_in_lat', 'check_in_lng',
        'outcome', 'sales_order_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'sequence' => 'integer',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'check_in_lat' => 'decimal:7',
        'check_in_lng' => 'decimal:7',
    ];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function beat(): BelongsTo
    {
        return $this->belongsTo(Beat::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
