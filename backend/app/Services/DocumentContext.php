<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders printable document Blade views to PDF outside of an HTTP request —
 * used by the email-send jobs, which run in a queue worker with no request or
 * "current company". The letterhead falls back to the tenant's first company.
 *
 * Kept deliberately parallel to the RendersDocumentPdf controller trait (which
 * serves the interactive download/stream endpoints) so both paths produce an
 * identical-looking document.
 */
class DocumentContext
{
    /** The tenant's primary company used as the document letterhead. */
    public function company(?Company $company = null): array
    {
        $company ??= Company::query()->orderBy('id')->first();

        $address = $company
            ? collect([$company->address, $company->city, $company->state, $company->pincode])
                ->filter()
                ->implode(', ')
            : null;

        return [
            'name' => $company?->name ?: config('app.name'),
            'legal_name' => $company?->legal_name,
            'address' => $address ?: null,
            'phone' => $company?->phone,
            'email' => $company?->email,
            'gstin' => $company?->gstin,
            'pan' => $company?->pan,
            'currency' => $company?->currency_symbol ?: Setting::currencySymbol(),
        ];
    }

    /** Render a document Blade view to raw PDF bytes (for mail attachments). */
    public function pdfBytes(string $view, array $data, string $paper = 'a4'): string
    {
        $data['company'] = $data['company'] ?? $this->company();
        $data['currency'] = $data['company']['currency'];
        $data['generatedAt'] = now()->format('d M Y, H:i');

        return Pdf::loadView($view, $data)->setPaper($paper)->output();
    }
}
