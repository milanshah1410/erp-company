<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exports\GenericArrayExport;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDataImport;
use App\Services\DataImportService;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * System Tools → Import Data (project-stack.md §8). Generic, per-module import
 * of Excel / CSV / JSON files, plus a downloadable column template per module.
 */
class DataImportController extends Controller
{
    /**
     * Imports up to this many rows run inline for an instant summary; larger
     * files are handed to the queue (§17 Performance — Queue) so a big upload
     * never ties up a web worker or hits the request timeout.
     */
    private const SYNC_ROW_LIMIT = 500;

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

    /**
     * Import an uploaded file into a module. Small files return a per-row
     * summary synchronously; large files are queued and return a 202 with a
     * job_id the client polls via status().
     */
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

        // Large imports go to the queue so the request returns immediately.
        if (count($rows) > self::SYNC_ROW_LIMIT) {
            $jobId = (string) Str::uuid();
            $statusKey = $this->statusKey($jobId);

            TenantCache::put($statusKey, [
                'status' => 'processing',
                'message' => 'Import queued — processing '.count($rows).' rows in the background.',
                'total' => count($rows),
            ], 3600);

            ProcessDataImport::dispatch(
                tenantId: (int) app(TenantContext::class)->id(),
                statusKey: $statusKey,
                module: $module,
                rows: $rows,
            );

            return response()->json([
                'queued' => true,
                'job_id' => $jobId,
                'message' => 'Import queued — processing '.count($rows).' rows in the background.',
            ], 202);
        }

        $result = $this->imports->import($module, $rows);

        return response()->json([
            'queued' => false,
            'message' => "Imported {$result['created']} record(s), {$result['failed']} failed.",
            ...$result,
        ]);
    }

    /** Poll the result of a queued import by its job_id. */
    public function status(string $jobId): JsonResponse
    {
        $result = TenantCache::get($this->statusKey($jobId));

        if ($result === null) {
            return response()->json(['status' => 'unknown', 'message' => 'No such import job (it may have expired).'], 404);
        }

        return response()->json($result);
    }

    private function statusKey(string $jobId): string
    {
        return 'import_job_'.$jobId;
    }
}
