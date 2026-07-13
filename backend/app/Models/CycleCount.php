<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CycleCount extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'cycle_count_number_prefix';

    protected $fillable = [
        'count_number', 'warehouse_id', 'status', 'count_date',
        'notes', 'created_by', 'completed_by', 'completed_at',
    ];

    protected $casts = [
        'count_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CycleCountItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

}
