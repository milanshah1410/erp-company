<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EWayBill extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'invoice_id', 'ewb_no', 'ewb_date', 'valid_upto',
        'transport_mode', 'transporter_id', 'transporter_name', 'transport_doc_no', 'transport_doc_date',
        'vehicle_no', 'vehicle_type', 'distance_km',
        'from_place', 'from_pincode', 'to_place', 'to_pincode',
        'status', 'cancel_reason_code', 'cancel_remarks', 'cancelled_at', 'error_message',
        'request_payload', 'response_payload', 'mode', 'generated_by',
    ];

    protected $casts = [
        'ewb_date' => 'datetime',
        'valid_upto' => 'datetime',
        'transport_doc_date' => 'date',
        'cancelled_at' => 'datetime',
        'distance_km' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
