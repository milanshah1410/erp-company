<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RateCard extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'product_id', 'standard_rate', 'is_active', 'notes',
    ];

    protected $casts = [
        'standard_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function pieceRates(): HasMany
    {
        return $this->hasMany(PieceRate::class);
    }

    /**
     * Effective per-piece rate: the sum of the operation (piece) rates when any
     * are defined, otherwise the card's standard rate. This is what a work
     * order snapshots at issue time.
     */
    public function effectiveRate(): float
    {
        $operations = (float) $this->pieceRates()->sum('rate');

        return round($operations > 0 ? $operations : (float) $this->standard_rate, 2);
    }
}
