<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EInvoice extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'invoice_id', 'irn', 'ack_no', 'ack_date', 'signed_invoice', 'signed_qr_code',
        'status', 'cancel_reason_code', 'cancel_remarks', 'cancelled_at', 'error_message',
        'request_payload', 'response_payload', 'mode', 'generated_by',
    ];

    protected $casts = [
        'ack_date' => 'datetime',
        'cancelled_at' => 'datetime',
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
