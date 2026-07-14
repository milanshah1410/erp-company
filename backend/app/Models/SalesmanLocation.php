<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesmanLocation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'salesman_id', 'recorded_at', 'latitude', 'longitude',
        'accuracy', 'speed', 'battery', 'source',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'accuracy' => 'decimal:2',
        'speed' => 'decimal:2',
        'battery' => 'integer',
    ];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }
}
