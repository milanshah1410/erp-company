<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeatCustomer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'beat_id', 'customer_id', 'sequence',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function beat(): BelongsTo
    {
        return $this->belongsTo(Beat::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
