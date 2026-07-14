<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed asset (machine, equipment, furniture, …). Master referenced by asset
 * maintenance and, optionally, factory maintenance — giving each asset a
 * service-cost history and next-service-due tracking.
 */
class Asset extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'asset_code', 'name', 'category', 'branch_id', 'location',
        'purchase_date', 'purchase_value', 'is_active',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }
}
