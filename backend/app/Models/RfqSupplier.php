<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfqSupplier extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'rfq_id', 'supplier_id', 'status', 'quoted_total',
        'lead_time_days', 'responded_at', 'remarks',
    ];

    protected $casts = [
        'quoted_total' => 'decimal:2',
        'lead_time_days' => 'integer',
        'responded_at' => 'datetime',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(RfqQuote::class);
    }
}
