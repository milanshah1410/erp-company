<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A performance bonus for a tailor. Bonuses may be derived from a rule
 * (pieces above target, efficiency, on-time delivery) or entered manually. A
 * bonus only credits the tailor's ledger once it is approved (or paid).
 */
class TailorBonus extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'tailor_bonus_number_prefix';

    protected $fillable = [
        'tailor_id', 'bonus_number', 'basis',
        'period_start', 'period_end', 'pieces', 'rate', 'amount',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'pieces' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(Tailor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A bonus counts toward the ledger once it is approved or paid. */
    public function isCredited(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }
}
