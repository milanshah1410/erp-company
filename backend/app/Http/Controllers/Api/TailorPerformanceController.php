<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Tailor;
use App\Models\TailorWork;
use App\Models\TailorWorkCompletion;
use Illuminate\Http\Request;

/**
 * Tailor performance reporting. `index` gives one aggregated row per tailor over
 * a date window (issued/completed/rejected pieces, reject %, efficiency, on-time
 * %, alterations, earnings + bonus); `rejections` is the detailed rejected-piece
 * list. Both reuse the shared JSON/CSV/Excel/PDF export responder.
 *
 * The window filters on the work's issue_date, i.e. "work issued in this period
 * and how it progressed". Bonuses/alterations use their own dates.
 */
class TailorPerformanceController extends Controller
{
    use ExportsReports;

    public function index(Request $request)
    {
        $from = $request->query('date_from');
        $to = $request->query('date_to');

        $tailors = Tailor::query()
            ->when($request->filled('contractor_id'), fn ($q) => $q->where('contractor_id', $request->query('contractor_id')))
            ->when($request->filled('tailor_id'), fn ($q) => $q->where('id', $request->query('tailor_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'contractor_id', 'opening_balance']);

        $ids = $tailors->pluck('id');

        // Work-order aggregates over the issue_date window.
        $works = TailorWork::query()
            ->whereIn('tailor_id', $ids)
            ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->selectRaw('tailor_id,
                SUM(issued_qty) issued,
                SUM(completed_qty) completed,
                SUM(rejected_qty) rejected,
                SUM(scrapped_qty) scrapped,
                SUM(issued_qty - completed_qty - scrapped_qty) pending,
                SUM(completed_qty * rate) earned')
            ->groupBy('tailor_id')
            ->get()->keyBy('tailor_id');

        // On-time delivery over completed work orders with a due date.
        $onTime = TailorWork::query()
            ->whereIn('tailor_id', $ids)
            ->where('status', 'completed')
            ->whereNotNull('due_date')
            ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->selectRaw('tailor_id, COUNT(*) total_due,
                SUM(CASE WHEN (SELECT MAX(c.completed_at) FROM tailor_work_completions c WHERE c.tailor_work_id = tailor_works.id) <= due_date THEN 1 ELSE 0 END) on_time')
            ->groupBy('tailor_id')
            ->get()->keyBy('tailor_id');

        $alterations = \App\Models\TailorAlteration::query()
            ->whereIn('tailor_id', $ids)
            ->when($from, fn ($q) => $q->whereDate('reported_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('reported_date', '<=', $to))
            ->selectRaw('tailor_id, COUNT(*) cnt, SUM(qty) qty')
            ->groupBy('tailor_id')
            ->get()->keyBy('tailor_id');

        $bonuses = \App\Models\TailorBonus::query()
            ->whereIn('tailor_id', $ids)
            ->whereIn('status', ['approved', 'paid'])
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->selectRaw('tailor_id, SUM(amount) amount')
            ->groupBy('tailor_id')
            ->get()->keyBy('tailor_id');

        $rows = $tailors->map(function ($t) use ($works, $onTime, $alterations, $bonuses) {
            $w = $works->get($t->id);
            $ot = $onTime->get($t->id);
            $alt = $alterations->get($t->id);
            $bon = $bonuses->get($t->id);

            $issued = (float) ($w->issued ?? 0);
            $completed = (float) ($w->completed ?? 0);
            $rejected = (float) ($w->rejected ?? 0);
            $submitted = $completed + $rejected;
            $earned = round((float) ($w->earned ?? 0), 2);
            $bonus = round((float) ($bon->amount ?? 0), 2);

            return [
                'tailor_id' => $t->id,
                'tailor' => $t->name,
                'code' => $t->code,
                'issued_qty' => round($issued, 2),
                'completed_qty' => round($completed, 2),
                'rejected_qty' => round($rejected, 2),
                'pending_qty' => round((float) ($w->pending ?? 0), 2),
                'reject_pct' => $submitted > 0 ? round($rejected / $submitted * 100, 1) : 0.0,
                'efficiency_pct' => $issued > 0 ? round($completed / $issued * 100, 1) : 0.0,
                'on_time_pct' => ($ot && $ot->total_due > 0) ? round($ot->on_time / $ot->total_due * 100, 1) : null,
                'alterations' => (int) ($alt->cnt ?? 0),
                'earned' => $earned,
                'bonus' => $bonus,
                'total_earned' => round($earned + $bonus, 2),
            ];
        })->all();

        $columns = [
            'code' => 'Code', 'tailor' => 'Tailor',
            'issued_qty' => 'Issued', 'completed_qty' => 'Completed', 'rejected_qty' => 'Rejected',
            'pending_qty' => 'Pending', 'reject_pct' => 'Reject %', 'efficiency_pct' => 'Efficiency %',
            'on_time_pct' => 'On-Time %', 'alterations' => 'Alterations',
            'earned' => 'Piece Earnings', 'bonus' => 'Bonus', 'total_earned' => 'Total Earned',
        ];

        return $this->exportResponse($request, $rows, $columns, 'tailor-performance', [
            'total_earned' => round(collect($rows)->sum('total_earned'), 2),
            'total_pieces' => round(collect($rows)->sum('completed_qty'), 2),
            'total_rejected' => round(collect($rows)->sum('rejected_qty'), 2),
        ]);
    }

    /** Rejected pieces: completion events that failed QC. */
    public function rejections(Request $request)
    {
        $query = TailorWorkCompletion::query()
            ->where('rejected_qty', '>', 0)
            ->with(['tailor:id,name', 'work:id,work_number,product_id', 'work.product:id,name,sku'])
            ->when($request->filled('tailor_id'), fn ($q) => $q->where('tailor_id', $request->query('tailor_id')))
            ->when($request->query('date_from'), fn ($q, $d) => $q->whereDate('completed_at', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->whereDate('completed_at', '<=', $d))
            ->orderByDesc('completed_at')->orderByDesc('id');

        $rows = $query->limit(10000)->get()->map(fn (TailorWorkCompletion $c) => [
            'date' => $c->completed_at?->toDateString(),
            'work_number' => $c->work?->work_number,
            'tailor' => $c->tailor?->name,
            'product' => $c->work?->product?->name,
            'accepted_qty' => (float) $c->qty,
            'rejected_qty' => (float) $c->rejected_qty,
            'action' => $c->reject_action,
            'reason' => $c->rejection_reason,
        ])->all();

        $columns = [
            'date' => 'Date', 'work_number' => 'Work #', 'tailor' => 'Tailor', 'product' => 'Product',
            'accepted_qty' => 'Accepted', 'rejected_qty' => 'Rejected', 'action' => 'Action', 'reason' => 'Reason',
        ];

        return $this->exportResponse($request, $rows, $columns, 'tailor-rejections', [
            'total_rejected' => round(collect($rows)->sum('rejected_qty'), 2),
        ]);
    }
}
