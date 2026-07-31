<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One table on the floor.
 *
 * `status` is kept in step with the order that occupies it — seating sets it to
 * occupied, settling frees it — but it is stored rather than derived so the
 * floor view is one query, and so 'reserved' and 'cleaning', which have no
 * order behind them, can be represented at all.
 */
class RestaurantTable extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'restaurant_section_id', 'name', 'capacity', 'status', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(RestaurantSection::class, 'restaurant_section_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(RestaurantOrder::class);
    }

    /** The order currently sitting at this table, if any. */
    public function openOrder(): HasOne
    {
        return $this->hasOne(RestaurantOrder::class)->where('status', 'open');
    }

    /** Can a party be seated here right now? */
    public function isSeatable(): bool
    {
        return $this->is_active && $this->status === 'free';
    }
}
