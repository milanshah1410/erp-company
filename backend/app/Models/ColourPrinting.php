<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ColourPrinting extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'printer_vendor_id', 'print_type', 'screen_count', 'colour_count',
        'cost', 'status', 'notes',
    ];

    protected $casts = [
        'screen_count' => 'integer',
        'colour_count' => 'integer',
        'cost' => 'decimal:2',
    ];

    /** The external printer this job is sent to (a supplier). */
    public function printerVendor(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'printer_vendor_id');
    }
}
