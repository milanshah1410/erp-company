<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch of hampers actually assembled — "40 × Diwali Deluxe, on the 12th".
 *
 * The document behind a two-way stock movement: contents out, hampers in. It
 * exists so that movement has something to point at, so the cost the hampers
 * entered stock at is recorded once and never recomputed, and so correcting a
 * miscount is a posted reversal rather than someone editing a quantity.
 *
 * `posted_at` is the guard. A shop assembling hampers in the back room enters
 * this on a phone with one bar of signal at the end of a long day; the double tap
 * is not hypothetical, and it must not empty the shelves twice.
 */
class GiftPackBuild extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'gift_pack_build_number_prefix';

    protected $fillable = [
        'build_number', 'pack_id', 'quantity', 'warehouse_id',
        'build_date', 'unit_cost', 'posted_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'build_date' => 'date',
        'unit_cost' => 'decimal:4',
        'posted_at' => 'datetime',
    ];

    protected $attributes = [
        'quantity' => 1,
        'unit_cost' => 0,
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(GiftPack::class, 'pack_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    /** What the whole batch was worth going in. */
    public function totalCost(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 2);
    }
}
