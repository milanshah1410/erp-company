<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmbroideryReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'embroidery_work_id', 'embroidery_vendor_id', 'qty', 'cost', 'amount',
        'received_at', 'received_to_stock', 'warehouse_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'cost' => 'decimal:2',
        'amount' => 'decimal:2',
        'received_at' => 'date',
        'received_to_stock' => 'boolean',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(EmbroideryWork::class, 'embroidery_work_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(EmbroideryVendor::class, 'embroidery_vendor_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
