<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'company_name', 'email', 'phone', 'gst_number',
        'address', 'city', 'state', 'country', 'pincode',
        'credit_limit', 'payment_terms_days', 'opening_balance', 'is_active',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'payment_terms_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(VendorRating::class);
    }

    /** Mean overall rating across all recorded ratings, or null if never rated. */
    public function averageRating(): ?float
    {
        $avg = $this->ratings()->avg('overall_score');

        return $avg !== null ? round((float) $avg, 2) : null;
    }

    /** Opening balance plus every unpaid rupee across approved purchase invoices. */
    public function outstandingBalance(): float
    {
        $unpaid = (float) $this->purchaseInvoices()
            ->where('status', 'approved')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as due')
            ->value('due');

        return round((float) $this->opening_balance + $unpaid, 2);
    }
}
