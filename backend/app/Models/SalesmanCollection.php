<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesmanCollection extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'salesman_collection_number_prefix';

    protected $fillable = [
        'collection_number', 'salesman_id', 'customer_id', 'invoice_id',
        'amount', 'mode', 'reference_no', 'collection_date', 'status',
        'deposited_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'collection_date' => 'date',
        'deposited_at' => 'datetime',
    ];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
