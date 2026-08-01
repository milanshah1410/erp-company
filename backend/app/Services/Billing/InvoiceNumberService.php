<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PlatformInvoiceSeries;
use App\Support\PlatformBillingProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hands out subscription tax-invoice numbers: `INV/2026-27/00001`.
 *
 * A GST tax invoice number must be unique and consecutive within the financial
 * year, which is why this cannot be derived from the invoice's row id — ids do not
 * restart in April, and a deleted draft would leave a gap an auditor asks about.
 *
 * The counter lives in one row per financial year and is incremented under
 * `lockForUpdate`, so two renewals settling in the same second queue rather than
 * collide. The unique index on `subscription_invoices.number` is the backstop if
 * they ever do.
 */
class InvoiceNumberService
{
    /**
     * Reserve and return the next number for the given date's financial year.
     *
     * Call this inside the same transaction that writes the invoice: if that
     * transaction rolls back the number is released too, keeping the series
     * gap-free.
     */
    public function next(?Carbon $on = null): string
    {
        $on ??= Carbon::now();
        $fy = self::financialYear($on);
        $prefix = (string) PlatformBillingProfile::get('invoice_prefix', 'INV');

        $connection = config('saas.central_connection', 'central');

        return DB::connection($connection)->transaction(function () use ($fy, $prefix): string {
            $series = PlatformInvoiceSeries::query()
                ->where('financial_year', $fy)
                ->lockForUpdate()
                ->first();

            if (! $series) {
                // The prefix is snapshotted when the series opens, so renaming it
                // mid-year cannot renumber invoices already issued under it.
                $series = PlatformInvoiceSeries::create([
                    'financial_year' => $fy,
                    'prefix' => $prefix,
                    'next_number' => 1,
                ]);
            }

            $number = $series->next_number;
            $series->update(['next_number' => $number + 1]);

            return sprintf('%s/%s/%05d', $series->prefix, $fy, $number);
        });
    }

    /**
     * The Indian financial year a date falls in: April–March, labelled '2026-27'.
     */
    public static function financialYear(?Carbon $on = null): string
    {
        $on ??= Carbon::now();
        $startYear = $on->month >= 4 ? $on->year : $on->year - 1;

        return $startYear.'-'.substr((string) ($startYear + 1), -2);
    }
}
