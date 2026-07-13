<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'company_name', 'email', 'phone', 'gst_number', 'type',
        'address', 'city', 'state', 'country', 'pincode',
        'credit_limit', 'opening_balance', 'is_active',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Opening balance plus every unpaid, uncredited rupee across invoices. */
    public function outstandingBalance(): float
    {
        $unpaid = (float) $this->invoices()
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as due')
            ->value('due');

        return round((float) $this->opening_balance + $unpaid, 2);
    }
}
