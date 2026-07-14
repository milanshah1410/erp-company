<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece (or set of pieces) sent back for adjustment after delivery. Optionally
 * tied to the originating work order and assigned to a tailor to redo the work.
 */
class TailorAlteration extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'tailor_alteration_number_prefix';

    protected $fillable = [
        'alteration_number', 'tailor_work_id', 'tailor_id', 'product_id',
        'qty', 'reason', 'status', 'charge',
        'reported_date', 'completed_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'charge' => 'decimal:2',
        'reported_date' => 'date',
        'completed_date' => 'date',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(TailorWork::class, 'tailor_work_id');
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(Tailor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
