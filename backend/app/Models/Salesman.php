<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Salesman extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'salesmen';

    protected $fillable = [
        'code', 'name', 'mobile', 'email', 'user_id', 'employee_id', 'branch_id',
        'territory', 'joining_date', 'default_incentive_percent', 'address', 'is_active',
    ];

    protected $casts = [
        'joining_date' => 'date',
        'default_incentive_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SalesmanTarget::class);
    }

    public function beats(): HasMany
    {
        return $this->hasMany(Beat::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(SalesmanVisit::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(SalesmanLocation::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(SalesmanCollection::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(SalesmanExpense::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** Most recent GPS ping — the salesman's live location. */
    public function latestLocation(): HasOne
    {
        return $this->hasOne(SalesmanLocation::class)->latestOfMany('recorded_at');
    }
}
