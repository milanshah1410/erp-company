<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One animal in the herd.
 *
 * Tracked per head rather than per batch because on a dairy farm the individual
 * is the unit that matters: this cow gives fourteen litres and that one gives
 * six, and the whole point of keeping records is to know which is which before
 * deciding what to feed and what to sell.
 */
class LivestockAnimal extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Statuses that mean the animal is still on the farm. */
    public const ON_FARM = ['active', 'dry'];

    protected $fillable = [
        'tag_number', 'name', 'species', 'breed', 'gender',
        'date_of_birth', 'acquired_on', 'acquisition_type', 'acquisition_cost',
        'supplier_id', 'warehouse_id', 'dam_tag', 'sire_tag',
        'lactation_number', 'status', 'exited_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'acquired_on' => 'date',
        'exited_on' => 'date',
        'acquisition_cost' => 'decimal:2',
        'lactation_number' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productionLogs(): HasMany
    {
        return $this->hasMany(FarmProductionLog::class, 'animal_id');
    }

    /** Still on the farm — milking or between lactations. */
    public function isOnFarm(): bool
    {
        return in_array($this->status, self::ON_FARM, true);
    }

    /**
     * Age in months, for the culling and heifer decisions that hang on it.
     * Null when the date of birth was never recorded — common for a bought cow.
     */
    public function ageMonths(): ?int
    {
        return $this->date_of_birth
            ? (int) $this->date_of_birth->diffInMonths(now())
            : null;
    }
}
