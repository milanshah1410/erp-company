<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shrinkage test result for a fabric roll / product: length & width measured
 * before and after a process (washing, dyeing, steaming), with the shrinkage
 * percentages derived at save time.
 */
class ShrinkageRecord extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'shrinkage_number_prefix';

    protected $fillable = [
        'record_number', 'fabric_roll_id', 'product_id', 'process',
        'length_before', 'length_after', 'width_before', 'width_after',
        'length_shrinkage_pct', 'width_shrinkage_pct',
        'test_date', 'tested_by', 'notes', 'created_by',
    ];

    protected $casts = [
        'length_before' => 'decimal:3',
        'length_after' => 'decimal:3',
        'width_before' => 'decimal:2',
        'width_after' => 'decimal:2',
        'length_shrinkage_pct' => 'decimal:2',
        'width_shrinkage_pct' => 'decimal:2',
        'test_date' => 'date',
    ];

    public function fabricRoll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Shrinkage % = (before − after) / before × 100. Zero when before is 0. */
    public static function shrinkagePct(?float $before, ?float $after): float
    {
        $before = (float) $before;
        if ($before <= 0) {
            return 0;
        }

        return round((($before - (float) $after) / $before) * 100, 2);
    }
}
