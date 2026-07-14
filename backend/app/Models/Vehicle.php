<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company vehicle. Master referenced by vehicle expenses and fuel expenses,
 * enabling per-vehicle running-cost and mileage reporting.
 */
class Vehicle extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'registration_no', 'name', 'type', 'make', 'model',
        'fuel_type', 'capacity_kg', 'purchase_date',
        'insurance_expiry', 'fitness_expiry', 'permit_expiry', 'puc_expiry',
        'is_active',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
        'purchase_date' => 'date',
        'insurance_expiry' => 'date',
        'fitness_expiry' => 'date',
        'permit_expiry' => 'date',
        'puc_expiry' => 'date',
        'is_active' => 'boolean',
    ];

    public function vehicleExpenses(): HasMany
    {
        return $this->hasMany(VehicleExpense::class);
    }

    public function fuelExpenses(): HasMany
    {
        return $this->hasMany(FuelExpense::class);
    }

    public function dispatchPlans(): HasMany
    {
        return $this->hasMany(DispatchPlan::class);
    }

    /** "MH-12-AB-1234 — Tempo" for pickers. */
    public function label(): string
    {
        return trim($this->registration_no.($this->name ? " — {$this->name}" : ''));
    }
}
