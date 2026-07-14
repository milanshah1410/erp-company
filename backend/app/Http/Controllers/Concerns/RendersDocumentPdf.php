<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Shared helpers for the printable-document controllers. Resolves the company
 * letterhead once and renders a Blade view to a PDF, streaming inline by default
 * or forcing a download when the request carries ?download=1.
 */
trait RendersDocumentPdf
{
    /**
     * The company whose letterhead heads the document: the user's active company,
     * falling back to the tenant's first company when none is selected.
     */
    protected function documentCompany(Request $request): array
    {
        $company = $request->user()?->currentCompany ?: Company::query()->orderBy('id')->first();

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

    /**
     * Render $view to a PDF and return it as an HTTP response. Streams inline for
     * in-browser preview/print; forces a file download when ?download=1 is set.
     */
    protected function renderDocument(Request $request, string $view, array $data, string $filename, string $paper = 'a4')
    {
        $data['company'] = $data['company'] ?? $this->documentCompany($request);
        $data['currency'] = $data['company']['currency'];
        $data['generatedAt'] = now()->format('d M Y, H:i');

        $pdf = Pdf::loadView($view, $data)->setPaper($paper);

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }
}
