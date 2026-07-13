<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'code', 'name', 'legal_name', 'gstin', 'pan', 'email', 'phone',
        'address', 'city', 'state', 'country', 'pincode',
        'financial_year_start_month', 'currency_code', 'currency_symbol', 'is_active',
    ];

    protected $casts = [
        'financial_year_start_month' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function financialYears(): HasMany
    {
        return $this->hasMany(FinancialYear::class);
    }

    public function gstRegistrations(): HasMany
    {
        return $this->hasMany(GstRegistration::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user');
    }
}
