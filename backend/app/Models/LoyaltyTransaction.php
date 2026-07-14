<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Transaction types that add points (stored with a positive sign). */
    public const CREDIT_TYPES = ['earn', 'adjust'];

    /** Transaction types that remove points (stored with a negative sign). */
    public const DEBIT_TYPES = ['redeem', 'expire'];

    protected $fillable = [
        'customer_id', 'type', 'points', 'invoice_id', 'description', 'created_by',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
