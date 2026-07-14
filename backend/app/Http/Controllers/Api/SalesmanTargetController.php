<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesmanTargetRequest;
use App\Http\Resources\SalesmanTargetResource;
use App\Models\SalesmanTarget;
use App\Services\SalesmanIncentiveService;
use Illuminate\Http\Request;

class SalesmanTargetController extends Controller
{
    public function __construct(private SalesmanIncentiveService $incentives)
    {
    }

    public function index(Request $request)
    {
        $query = SalesmanTarget::query()->with('salesman:id,name,code,default_incentive_percent');

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->query('salesman_id'));
        }
        if ($request->filled('period_year')) {
            $query->where('period_year', $request->query('period_year'));
        }
        if ($request->filled('period_month')) {
            $query->where('period_month', $request->query('period_month'));
        }

        $query->orderByDesc('period_year')->orderByDesc('period_month');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $page = $query->paginate($perPage);

        // Attach live achievement to each target when requested (list screens
        // can opt out for speed with ?with_achievement=0).
        if ($request->boolean('with_achievement', true)) {
            $page->getCollection()->each(function (SalesmanTarget $target) {
                $target->achievement = $this->incentives->achievement(
                    $target->salesman, $target->period_year, $target->period_month, $target
                );
            });
        }

        return SalesmanTargetResource::collection($page);
    }

    public function store(SalesmanTargetRequest $request)
    {
        $target = SalesmanTarget::create($request->validated());

        return (new SalesmanTargetResource($target->load('salesman')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SalesmanTarget $salesmanTarget)
    {
        $salesmanTarget->load('salesman');
        $salesmanTarget->achievement = $this->incentives->achievement(
            $salesmanTarget->salesman, $salesmanTarget->period_year, $salesmanTarget->period_month, $salesmanTarget
        );

        return new SalesmanTargetResource($salesmanTarget);
    }

    public function update(SalesmanTargetRequest $request, SalesmanTarget $salesmanTarget)
    {
        $salesmanTarget->update($request->validated());

        return new SalesmanTargetResource($salesmanTarget->load('salesman'));
    }

    public function destroy(SalesmanTarget $salesmanTarget)
    {
        $salesmanTarget->delete();

        return response()->json(['message' => 'Target deleted.']);
    }
}
