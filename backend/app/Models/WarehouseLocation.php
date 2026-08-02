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
        // Food & Beverage plugin: an ice-cream parlour's freezers are locations
        // like any other, flagged so the parlour screen knows which to read and
        // what temperature each is meant to hold.
        'is_freezer', 'target_temp_c',
        // Grocery plugin, supermarket trade: the locations a *customer* picks
        // from, plus the address a shopper would use to describe where one is.
        // Replenishment is exactly the movement from an unflagged location (the
        // backroom) to a flagged one, so the flag is what makes the two ends of
        // it distinguishable.
        'is_shelf', 'aisle', 'bay', 'shelf_level',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_freezer' => 'boolean',
        'target_temp_c' => 'decimal:2',
        'is_shelf' => 'boolean',
        'shelf_level' => 'integer',
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
