<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TailorWorkCompletion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tailor_work_id', 'tailor_id', 'qty', 'rate', 'amount',
        'completed_at', 'received_to_stock', 'warehouse_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'completed_at' => 'date',
        'received_to_stock' => 'boolean',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(TailorWork::class, 'tailor_work_id');
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(Tailor::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
