<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of dishes fired to one station.
 *
 * One round of ordering can produce several KOTs — the drinks go to the bar and
 * the kebabs to the tandoor — because a station should only ever see its own
 * work. `ready` and `served` are separate states because the pass calls ready
 * and the floor calls served, and the gap between the two is the number a
 * manager actually watches.
 */
class KitchenOrderTicket extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'kot_number_prefix';

    /** The stations a kitchen can be split into. */
    public const STATIONS = ['kitchen', 'bar', 'tandoor', 'dessert', 'chinese'];

    protected $fillable = [
        'kot_number', 'restaurant_order_id', 'station', 'status',
        'fired_at', 'ready_at', 'served_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'fired_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrder::class, 'restaurant_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantOrderItem::class, 'kitchen_order_ticket_id');
    }

    /** Minutes since it was fired — what the kitchen display sorts on. */
    public function waitingMinutes(): ?int
    {
        if (! $this->fired_at || in_array($this->status, ['served', 'cancelled'], true)) {
            return null;
        }

        return (int) $this->fired_at->diffInMinutes(now());
    }
}
