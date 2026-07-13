<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    use HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'adjustment_number_prefix';

    /** Adjustment reason → immutable stock-ledger movement type. */
    public const LEDGER_TYPES = [
        'physical_mismatch' => 'adjustment',
        'damage' => 'damage',
        'theft' => 'adjustment',
        'correction' => 'adjustment',
        'opening_stock' => 'opening',
        'sample_issue' => 'sample',
        'consumption' => 'consumption',
    ];

    protected $fillable = [
        'adjustment_number', 'warehouse_id', 'reason', 'status',
        'adjustment_date', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

}
