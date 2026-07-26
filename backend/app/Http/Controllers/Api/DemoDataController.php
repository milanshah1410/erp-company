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
 * sample records to explore the ERP. The quick volumes (10/20/50) run inline;
 * the bulk volumes (1K/100K/1M) go to the queue so they never block a request.
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
            'bulk_counts' => DemoDataGenerator::BULK_COUNTS,
        ]);
    }

    /**
     * Generate demo records for the selected modules + volume.
     *
     * The quick volumes are small (a few dozen rows), so they run inline within
     * the request and the response carries the per-module counts created — the
     * new records are visible the moment the call returns. The bulk volumes
     * (1K / 100K / 1M) would blow the request timeout, so they are dispatched to
     * the queue instead and the response only acknowledges the hand-off.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(DemoDataGenerator::MODULES))],
            'count' => ['required', 'integer', Rule::in(DemoDataGenerator::allCounts())],
        ]);

        $tenantId = app(TenantContext::class)->id();
        $userId = $request->user()->id;

        if (in_array($data['count'], DemoDataGenerator::BULK_COUNTS, true)) {
            GenerateDemoData::dispatch($tenantId, $userId, array_values($data['modules']), $data['count']);

            $volume = number_format($data['count']);

            return response()->json([
                'message' => "Queued {$volume} record(s) per module for " . count($data['modules'])
                    . ' module(s). Generation runs in the background — refresh the module screen to watch rows arrive.',
                'queued' => true,
                'created' => [],
            ], 202);
        }

        // Re-establish the tenant context (queue-tenancy convention) so every
        // generated row is tenant-stamped, exactly as the old job did.
        $created = app(TenantContext::class)->runWith($tenantId, fn () => (new DemoDataGenerator($userId))
            ->generate(array_values($data['modules']), $data['count']));

        $total = array_sum($created);

        return response()->json([
            'message' => "Created {$total} record(s) across " . count($data['modules']) . ' module(s).',
            'created' => $created,
        ]);
    }
}
