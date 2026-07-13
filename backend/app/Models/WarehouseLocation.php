<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'warehouse_id', 'parent_id', 'code', 'name', 'type', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class, 'parent_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockLocation::class, 'location_id');
    }
}
