<?php

namespace App\Http\Controllers\Concerns;

use App\Exports\GenericArrayExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Shared JSON/CSV/Excel/PDF responder for report endpoints.
 *
 * $rows are associative arrays keyed by field name (as returned to the
 * on-screen table). $columns maps the fields to include in an export, in
 * order, to their display label: ['sku' => 'SKU', 'name' => 'Product'].
 */
trait ExportsReports
{
    protected function exportResponse(Request $request, array $rows, array $columns, string $filename, array $meta = [])
    {
        $format = $request->query('format', 'json');

        if ($format === 'json') {
            return response()->json(['data' => $rows, 'meta' => $meta]);
        }

        $headings = array_values($columns);
        $exportRows = array_map(
            fn ($row) => array_map(fn ($key) => data_get($row, $key), array_keys($columns)),
            $rows
        );

        return match ($format) {
            'csv', 'xlsx' => Excel::download(new GenericArrayExport($exportRows, $headings), "{$filename}.{$format}"),
            'pdf' => Pdf::loadView('reports.pdf', [
                'title' => $filename,
                'generatedAt' => now()->format('Y-m-d H:i'),
                'headings' => $headings,
                'rows' => $exportRows,
            ])->download("{$filename}.pdf"),
            default => response()->json(['message' => 'Unsupported format'], 422),
        };
    }
}
