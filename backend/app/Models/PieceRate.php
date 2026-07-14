<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceRate extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'rate_card_id', 'operation', 'rate', 'sequence',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'sequence' => 'integer',
    ];

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }
}
