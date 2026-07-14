<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqQuote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'rfq_id', 'rfq_supplier_id', 'rfq_item_id',
        'unit_price', 'tax_percent', 'remarks',
    ];

    protected $casts = [
        'unit_price' => 'decimal:4',
        'tax_percent' => 'decimal:2',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function rfqSupplier(): BelongsTo
    {
        return $this->belongsTo(RfqSupplier::class);
    }

    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class);
    }
}
