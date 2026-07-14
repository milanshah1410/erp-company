<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerVisit extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'customer_id', 'visited_at', 'purpose', 'location', 'notes', 'outcome', 'user_id',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
