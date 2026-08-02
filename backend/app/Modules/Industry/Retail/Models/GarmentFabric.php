<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fabric, as a showroom knows it: what a garment is made of, how wide it comes
 * off the roll and how it should be washed.
 *
 * Not to be confused with `FabricRoll`, which is the mill's record of a physical
 * roll moving through inspection, shrinkage and cutting. A shop selling shirts
 * has no rolls; it has a line on the label.
 */
class GarmentFabric extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'composition', 'weave', 'gsm', 'width_inch',
        'shrinkage_percent', 'care', 'rate', 'supplier_id', 'notes', 'is_active',
    ];

    protected $casts = [
        'gsm' => 'integer',
        'width_inch' => 'decimal:2',
        'shrinkage_percent' => 'decimal:2',
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Codes are typed at a counter and printed on labels; case drift turns
        // one fabric into two the first time somebody types "cot-01".
        static::saving(function (GarmentFabric $fabric) {
            if ($fabric->code) {
                $fabric->code = strtoupper(trim($fabric->code));
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function styles(): HasMany
    {
        return $this->hasMany(GarmentStyle::class, 'fabric_id');
    }
}
