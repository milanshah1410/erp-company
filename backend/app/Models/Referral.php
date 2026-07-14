<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'referrer_customer_id', 'referred_name', 'referred_phone', 'referred_customer_id',
        'status', 'reward_points', 'notes', 'joined_at', 'rewarded_at', 'created_by',
    ];

    protected $casts = [
        'reward_points' => 'integer',
        'joined_at' => 'datetime',
        'rewarded_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_customer_id');
    }

    public function referredCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_customer_id');
    }
}
