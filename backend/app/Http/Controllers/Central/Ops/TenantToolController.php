<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Ops;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDemoData;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DemoDataGenerator;
use App\Services\PerformanceMetrics;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform Ops → Tenant tools. Operator-only surfaces that used to sit on the
 * tenant's own System Tools screen:
 *
 *  - Performance diagnostics (§17) — infrastructure health, read inside the
 *    selected tenant so its cache-group versions come out scoped.
 *  - Demo Data generator (§7) — writes sample rows into the selected tenant.
 *
 * The tenant keeps only Import Data. Everything here runs through
 * TenantContext::runWith so every generated row is tenant-stamped (queue-tenancy
 * convention), and the bulk volumes are dispatched to the queue instead of
 * blocking the request.
 */
class TenantToolController extends Controller
{
    /** Diagnostics for one tenant. */
    public function performance(Tenant $tenant, PerformanceMetrics $metrics): JsonResponse
    {
        $data = app(TenantContext::class)->runWith($tenant->id, fn () => $metrics->collect());

        return response()->json($data + [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->company_name],
        ]);
    }

    /** Module catalogue + allowed volumes for the demo-data picker. */
    public function demoOptions(): JsonResponse
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
     * Generate demo records into a tenant. The quick volumes run inline and
     * report what was created; the bulk volumes (1K/100K/1M) would blow the
     * request timeout, so they go to the queue and the response only
     * acknowledges the hand-off.
     */
    public function generateDemo(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(DemoDataGenerator::MODULES))],
            'count' => ['required', 'integer', Rule::in(DemoDataGenerator::allCounts())],
        ]);

        // Generated rows are stamped with a creator, so the run needs a user that
        // belongs to the target tenant — the operator's own id is central-side.
        $userId = app(TenantContext::class)->runWith(
            $tenant->id,
            fn () => User::query()->orderBy('id')->value('id'),
        );

        if (! $userId) {
            return response()->json([
                'message' => "Tenant \"{$tenant->company_name}\" has no users yet — provisioning must finish first.",
            ], 422);
        }

        $modules = array_values($data['modules']);

        if (in_array($data['count'], DemoDataGenerator::BULK_COUNTS, true)) {
            GenerateDemoData::dispatch($tenant->id, $userId, $modules, $data['count']);

            $volume = number_format($data['count']);

            return response()->json([
                'message' => "Queued {$volume} record(s) per module for {$tenant->company_name} across "
                    . count($modules) . ' module(s). Generation runs in the background.',
                'queued' => true,
                'created' => [],
            ], 202);
        }

        $created = app(TenantContext::class)->runWith(
            $tenant->id,
            fn () => (new DemoDataGenerator($userId))->generate($modules, $data['count']),
        );

        return response()->json([
            'message' => 'Created ' . array_sum($created) . " record(s) in {$tenant->company_name} across "
                . count($modules) . ' module(s).',
            'created' => $created,
        ]);
    }
}
