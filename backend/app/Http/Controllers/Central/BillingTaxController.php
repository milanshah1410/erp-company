<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoiceSeries;
use App\Services\Billing\InvoiceNumberService;
use App\Support\GstinValidator;
use App\Support\GstStateCodes;
use App\Support\PlatformBillingProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The operator's own GST & invoicing profile (Volume 11 §Billing → GST).
 *
 * This is the seller side of every subscription invoice: who we are on the
 * invoice, the GSTIN we collect under, the rate, and the number series. Guarded by
 * the `settings.*` central permissions — it is platform configuration, not a
 * per-tenant billing action.
 *
 * The state code is not editable in the normal case: it is read out of the GSTIN,
 * because the first two digits of a GSTIN *are* the state of registration, and a
 * mismatch between them is always a mistake. It is only accepted directly for an
 * unregistered deployment that still wants a place of supply on record.
 */
class BillingTaxController extends Controller
{
    public function show(): JsonResponse
    {
        $profile = PlatformBillingProfile::all();
        $fy = InvoiceNumberService::financialYear();
        $series = PlatformInvoiceSeries::where('financial_year', $fy)->first();

        return response()->json([
            'data' => $profile + [
                'state_name' => $profile['state_code'] ? GstStateCodes::name($profile['state_code']) : null,
                'charges_tax' => PlatformBillingProfile::chargesTax(),
            ],
            'series' => [
                'financial_year' => $fy,
                // The prefix in force for this year is the one snapshotted when the
                // series opened — editing the profile's prefix now only affects the
                // next financial year.
                'prefix' => $series?->prefix ?? $profile['invoice_prefix'],
                'next_number' => $series?->next_number ?? 1,
                'next_preview' => sprintf(
                    '%s/%s/%05d',
                    $series?->prefix ?? $profile['invoice_prefix'],
                    $fy,
                    $series?->next_number ?? 1,
                ),
                'locked' => $series !== null,
            ],
            'states' => collect(GstStateCodes::MAP)
                ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
                ->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'gstin' => ['sometimes', 'nullable', 'string', 'size:15'],
            'state_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'pan' => ['sometimes', 'nullable', 'string', 'max:10'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:28'],
            'sac_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'prices_include_tax' => ['sometimes', 'boolean'],
            'invoice_prefix' => ['sometimes', 'string', 'max:12', 'regex:/^[A-Za-z0-9\/-]+$/'],
            'invoice_terms' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // A malformed GSTIN on our own invoices is the kind of error that fails a
        // customer's input-credit claim, so it is checked properly (checksum
        // included) rather than by length alone.
        if (! empty($data['gstin']) && ! GstinValidator::isValid($data['gstin'])) {
            return response()->json([
                'message' => 'That GSTIN is not valid.',
                'errors' => ['gstin' => ['Enter a valid 15-character GSTIN.']],
            ], 422);
        }

        if (! empty($data['state_code']) && ! GstStateCodes::name($data['state_code'])) {
            return response()->json([
                'message' => 'That is not a recognised GST state code.',
                'errors' => ['state_code' => ['Unknown state code.']],
            ], 422);
        }

        PlatformBillingProfile::save($data);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'changed' => array_keys($data)])
            ->event('billing-profile-updated')
            ->log('Operator updated the platform GST & invoicing profile');

        return $this->show();
    }
}
