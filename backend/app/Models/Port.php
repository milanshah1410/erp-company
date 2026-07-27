<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port master — sea port, airport, inland container depot or container freight
 * station. Used as port of loading / discharge on import & export shipments.
 */
class Port extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Port kinds; `sea` and `air` carry the vessel / flight legs. */
    public const TYPES = ['sea', 'air', 'icd', 'cfs'];

    protected $fillable = [
        'code', 'name', 'type', 'country_id', 'city', 'state', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function chaAgents(): HasMany
    {
        return $this->hasMany(ChaAgent::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
