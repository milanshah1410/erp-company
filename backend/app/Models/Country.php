<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Country master (Import & Export Logistics). Origin / destination of every
 * shipment and the home country of overseas parties; `currency_code` seeds the
 * invoice currency for trade documents.
 */
class Country extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'iso3', 'name', 'currency_code', 'dial_code', 'region', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function ports(): HasMany
    {
        return $this->hasMany(Port::class);
    }

    public function freightForwarders(): HasMany
    {
        return $this->hasMany(FreightForwarder::class);
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
