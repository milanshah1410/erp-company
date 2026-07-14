<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cutting operation against a fabric roll. A length is drawn off the roll and
 * split into productive consumption, cutting loss (wastage) and a reusable
 * leftover remnant:
 *
 *   length_used = length_consumed + cutting_loss + leftover_length
 *
 * Any leftover generates a child leftover FabricRoll so remnants stay tracked
 * and re-usable.
 */
class FabricCutting extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'cutting_number_prefix';

    protected $fillable = [
        'cutting_number', 'fabric_roll_id', 'warehouse_id', 'cut_date',
        'length_used', 'length_consumed', 'cutting_loss', 'leftover_length',
        'pieces_produced', 'leftover_roll_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'cut_date' => 'date',
        'length_used' => 'decimal:3',
        'length_consumed' => 'decimal:3',
        'cutting_loss' => 'decimal:3',
        'leftover_length' => 'decimal:3',
        'pieces_produced' => 'integer',
    ];

    public function fabricRoll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function leftoverRoll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class, 'leftover_roll_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Cutting-loss as a percentage of the length drawn off the roll. */
    public function getLossPctAttribute(): float
    {
        $used = (float) $this->length_used;
        if ($used <= 0) {
            return 0;
        }

        return round(((float) $this->cutting_loss / $used) * 100, 2);
    }
}
