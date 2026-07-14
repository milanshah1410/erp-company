<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRating extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'supplier_id', 'goods_receipt_id', 'purchase_order_id',
        'quality_score', 'delivery_score', 'pricing_score', 'service_score',
        'overall_score', 'rated_on', 'remarks', 'rated_by',
    ];

    protected $casts = [
        'quality_score' => 'integer',
        'delivery_score' => 'integer',
        'pricing_score' => 'integer',
        'service_score' => 'integer',
        'overall_score' => 'decimal:2',
        'rated_on' => 'date',
    ];

    /** Mean of the four category scores, rounded to 2 dp. */
    public static function computeOverall(int $quality, int $delivery, int $pricing, int $service): float
    {
        return round(($quality + $delivery + $pricing + $service) / 4, 2);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }
}
