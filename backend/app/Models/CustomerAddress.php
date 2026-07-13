<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'customer_id', 'type', 'label', 'is_default', 'contact_name', 'phone',
        'line1', 'line2', 'city', 'state', 'country', 'pincode', 'gstin',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Single-line rendering, e.g. for documents. */
    public function formatted(): string
    {
        return collect([
            $this->line1, $this->line2, $this->city, $this->state, $this->pincode, $this->country,
        ])->filter()->implode(', ');
    }
}
