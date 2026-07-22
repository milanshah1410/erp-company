<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * Generate demo records for the selected modules + volume.
     *
     * The volumes are small (a few dozen rows), so this runs inline within the
     * request and returns the per-module counts created — no queue worker needed,
     * and the new records are visible the moment the call returns.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(DemoDataGenerator::MODULES))],
            'count' => ['required', 'integer', Rule::in(DemoDataGenerator::COUNTS)],
        ]);

        $tenantId = app(TenantContext::class)->id();
        $userId = $request->user()->id;

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
