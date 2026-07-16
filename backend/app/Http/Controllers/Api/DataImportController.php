<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exports\GenericArrayExport;
use App\Http\Controllers\Controller;
use App\Services\DataImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * System Tools → Import Data (project-stack.md §8). Generic, per-module import
 * of Excel / CSV / JSON files, plus a downloadable column template per module.
 */
class DataImportController extends Controller
{
    public function __construct(private DataImportService $imports) {}

    /** List the modules that support import (for the UI). */
    public function modules(): JsonResponse
    {
        return response()->json(['modules' => DataImportService::modules()]);
    }

    /** Download an Excel template (header row only) for a module. */
    public function template(string $module): BinaryFileResponse
    {
        abort_unless(DataImportService::isModule($module), 404);

        $columns = DataImportService::templateColumns($module);

        return Excel::download(
            new GenericArrayExport([], $columns),
            "{$module}-import-template.xlsx",
        );
    }

    /** Import an uploaded file into a module; returns a per-row summary. */
    public function store(Request $request, string $module): JsonResponse
    {
        abort_unless(DataImportService::isModule($module), 404);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt,json', 'max:10240'],
        ]);

        $rows = $this->imports->parse($request->file('file'));

        if (empty($rows)) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows.']);
        }

        $result = $this->imports->import($module, $rows);

        return response()->json([
            'message' => "Imported {$result['created']} record(s), {$result['failed']} failed.",
            ...$result,
        ]);
    }
}
