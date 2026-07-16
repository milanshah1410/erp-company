<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDemoData;
use App\Services\DemoDataGenerator;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * System Tools → Demo Data (project-stack.md §7): lets an admin bulk-generate
 * sample records to explore the ERP. Generation runs on the queue so large
 * volumes (up to 10,000 per module) never block the request.
 */
class DemoDataController extends Controller
{
    /** The module catalogue + allowed volumes, for building the UI. */
    public function options(): JsonResponse
    {
        return response()->json([
            'modules' => collect(DemoDataGenerator::MODULES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'counts' => DemoDataGenerator::COUNTS,
        ]);
    }

    /**
     * Queue a demo-data generation run for the selected modules + volume.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(DemoDataGenerator::MODULES))],
            'count' => ['required', 'integer', Rule::in(DemoDataGenerator::COUNTS)],
        ]);

        $tenantId = app(TenantContext::class)->id();

        GenerateDemoData::dispatch(
            tenantId: $tenantId,
            userId: $request->user()->id,
            modules: array_values($data['modules']),
            count: $data['count'],
        );

        return response()->json([
            'message' => "Generating {$data['count']} record(s) for " . count($data['modules'])
                . ' module(s). This runs in the background and will appear shortly.',
        ], 202);
    }
}
