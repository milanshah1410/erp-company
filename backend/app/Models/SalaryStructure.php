<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructure extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'effective_from', 'basic', 'hra', 'conveyance', 'medical',
        'special_allowance', 'other_allowances',
        'pf_applicable', 'esi_applicable', 'pt_applicable', 'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'basic' => 'decimal:2',
        'hra' => 'decimal:2',
        'conveyance' => 'decimal:2',
        'medical' => 'decimal:2',
        'special_allowance' => 'decimal:2',
        'other_allowances' => 'decimal:2',
        'pf_applicable' => 'boolean',
        'esi_applicable' => 'boolean',
        'pt_applicable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Total fixed monthly earnings defined by this structure. */
    public function grossEarnings(): float
    {
        return round(
            (float) $this->basic + (float) $this->hra + (float) $this->conveyance
            + (float) $this->medical + (float) $this->special_allowance + (float) $this->other_allowances,
            2
        );
    }
}
