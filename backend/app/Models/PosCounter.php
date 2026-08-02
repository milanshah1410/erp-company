<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A physical till.
 *
 * Core rather than a Supermarket-module table, and the choice is worth stating
 * because the feature list that asked for it says "Supermarket → Multi Counter
 * POS". A counter is not a supermarket idea: it is the answer to "which drawer
 * did this money go into", which any shop with two tills has to answer and only
 * a shop with one till can ignore. A jeweller with a separate silver counter and
 * a showroom with a first-floor cash desk both reconcile per desk, and gating
 * that on `supermarket` would have sold them a grocery module to get it.
 *
 * What *is* supermarket-specific is `mode = 'self_checkout'`: an unattended lane
 * that the customer works and a supervisor authorises. The flag lives here
 * because the counter row is core; the screens that drive such a lane are the
 * Retail plugin's and are gated `module:supermarket`.
 *
 * @property-read PosCounterSession|null $openSession
 */
class PosCounter extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'warehouse_id', 'code', 'name', 'mode', 'location',
        'default_float', 'printer_device_id', 'is_active',
    ];

    protected $casts = [
        'default_float' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults.
     *
     * Needed because `PosCounter::create([...])` without these keys returns an
     * instance whose `is_active` is null until it is re-read, and the very next
     * thing a caller does is open a session on it — which checks exactly that
     * attribute and would refuse a counter the database considers active.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mode' => 'attended',
        'default_float' => 0,
        'is_active' => true,
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'printer_device_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PosCounterSession::class, 'counter_id');
    }

    /**
     * The shift currently being worked, if any.
     *
     * A `hasOne` rather than a `sessions()->where(...)->first()` at every call
     * site, so the counters list can eager-load it in one query instead of one
     * per lane.
     */
    public function openSession(): HasOne
    {
        return $this->hasOne(PosCounterSession::class, 'counter_id')
            ->where('status', 'open');
    }

    public function isSelfCheckout(): bool
    {
        return $this->mode === 'self_checkout';
    }
}
