<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Beat extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'salesman_id', 'name', 'day_of_week', 'area', 'notes', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /** Ordered customer stops on this beat (the route). */
    public function stops(): HasMany
    {
        return $this->hasMany(BeatCustomer::class)->orderBy('sequence');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(SalesmanVisit::class);
    }
}
