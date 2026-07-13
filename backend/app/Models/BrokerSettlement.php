<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerSettlement extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'broker_settlement_number_prefix';

    protected $fillable = [
        'broker_id', 'settlement_number', 'amount', 'mode',
        'settlement_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'settlement_date' => 'date',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BrokerSettlementAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
