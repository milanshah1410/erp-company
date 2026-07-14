<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManufacturingOrderStage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'manufacturing_order_id', 'stage_key', 'name', 'sequence',
        'assigned_employee_id', 'start_date', 'end_date', 'status',
        'cost', 'remarks', 'image',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'sequence' => 'integer',
        'cost' => 'decimal:2',
    ];

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }
}
