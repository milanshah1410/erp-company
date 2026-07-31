<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled medicine actually handed over, against one prescription.
 *
 * This is the row the Schedule H1 / X register is printed from, so it keeps its
 * own copy of the drug name, schedule and quantity. Reading those through to
 * the product would let a later rename or reclassification quietly rewrite a
 * register an inspector has already seen.
 */
class PrescriptionDispense extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Schedules whose sales must be entered in a retained register. */
    public const REGISTERED_SCHEDULES = ['h1', 'x'];

    protected $fillable = [
        'prescription_id', 'invoice_id', 'sales_order_id',
        'product_id', 'variant_id', 'batch_id',
        'product_name', 'drug_schedule', 'quantity', 'dispensed_on', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'dispensed_on' => 'date',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
