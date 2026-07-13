<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateEntry extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'gate_entry_number_prefix';

    protected $fillable = [
        'entry_number', 'warehouse_id', 'direction', 'vehicle_number', 'driver_name', 'driver_contact',
        'reference_type', 'reference_id', 'reference_number', 'purpose', 'status',
        'gate_in_at', 'gate_out_at', 'logged_by', 'closed_by',
    ];

    protected $casts = [
        'gate_in_at' => 'datetime',
        'gate_out_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
