<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One financial year of the subscription tax-invoice number series.
 *
 * Only {@see \App\Services\Billing\InvoiceNumberService} touches this — it takes a
 * row lock while incrementing `next_number`, which is why the counter lives in a
 * row of its own rather than in a platform setting.
 */
class PlatformInvoiceSeries extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    protected $table = 'platform_invoice_series';

    protected $fillable = ['financial_year', 'prefix', 'next_number'];

    protected $casts = ['next_number' => 'integer'];
}
