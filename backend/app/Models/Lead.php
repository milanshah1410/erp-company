<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Lead extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    /** @see \App\Models\Setting::NUMBER_PREFIX_DEFAULTS */
    protected static string $numberSettingKey = 'lead_number_prefix';

    /** Pipeline stages, in order. */
    public const STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

    protected $fillable = [
        'code', 'name', 'company_name', 'email', 'phone', 'source', 'status',
        'estimated_value', 'expected_close_date', 'assigned_to', 'notes',
        'lost_reason', 'converted_customer_id', 'converted_at', 'created_by',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'expected_close_date' => 'date',
        'converted_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function followUps(): MorphMany
    {
        return $this->morphMany(FollowUp::class, 'followupable');
    }

    public function isConverted(): bool
    {
        return $this->converted_customer_id !== null;
    }
}
