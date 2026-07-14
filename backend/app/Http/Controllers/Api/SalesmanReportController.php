<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salesman;
use App\Models\SalesmanVisit;
use App\Services\SalesmanIncentiveService;
use Illuminate\Http\Request;

/**
 * Salesman module reports: incentive/target achievement across the team and
 * visit-plan compliance.
 */
class SalesmanReportController extends Controller
{
    public function __construct(private SalesmanIncentiveService $incentives)
    {
    }

    /**
     * Target vs achievement and computed incentive for every active salesman
     * in a period (defaults to the current month).
     */
    public function incentive(Request $request)
    {
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        $rows = Salesman::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Salesman $salesman) use ($year, $month) {
                $perf = $this->incentives->achievement($salesman, $year, $month);

                return [
                    'salesman_id' => $salesman->id,
                    'code' => $salesman->code,
                    'name' => $salesman->name,
                    ...$perf,
                ];
            })
            ->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'period_year' => $year,
                'period_month' => $month,
                'total_target' => round($rows->sum('target_amount'), 2),
                'total_achieved' => round($rows->sum('achieved_amount'), 2),
                'total_incentive' => round($rows->sum('incentive_amount'), 2),
            ],
        ]);
    }

    /**
     * Visit-plan compliance: planned vs visited/skipped counts per salesman
     * over a date range (defaults to the current month).
     */
    public function visitCompliance(Request $request)
    {
        $from = $request->query('date_from', now()->startOfMonth()->toDateString());
        $to = $request->query('date_to', now()->endOfMonth()->toDateString());

        $rows = SalesmanVisit::query()
            ->join('salesmen', 'salesmen.id', '=', 'salesman_visits.salesman_id')
            ->whereBetween('salesman_visits.visit_date', [$from, $to])
            ->selectRaw('salesman_visits.salesman_id, salesmen.name as salesman, salesmen.code as code')
            ->selectRaw('COUNT(*) as planned')
            ->selectRaw("SUM(CASE WHEN salesman_visits.status = 'visited' THEN 1 ELSE 0 END) as visited")
            ->selectRaw("SUM(CASE WHEN salesman_visits.status = 'skipped' THEN 1 ELSE 0 END) as skipped")
            ->groupBy('salesman_visits.salesman_id', 'salesmen.name', 'salesmen.code')
            ->orderBy('salesmen.name')
            ->get()
            ->map(function ($r) {
                $planned = (int) $r->planned;
                $visited = (int) $r->visited;

                return [
                    'salesman_id' => $r->salesman_id,
                    'code' => $r->code,
                    'salesman' => $r->salesman,
                    'planned' => $planned,
                    'visited' => $visited,
                    'skipped' => (int) $r->skipped,
                    'compliance_percent' => $planned > 0 ? round($visited / $planned * 100, 2) : 0.0,
                ];
            });

        return response()->json([
            'data' => $rows,
            'summary' => ['date_from' => $from, 'date_to' => $to],
        ]);
    }
}
