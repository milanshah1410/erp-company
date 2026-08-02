<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Mobile\Http\Requests\EmiPlanRequest;
use App\Modules\Industry\Mobile\Models\EmiInstalment;
use App\Modules\Industry\Mobile\Models\EmiPlan;
use App\Modules\Industry\Mobile\Models\Handset;
use App\Modules\Industry\Mobile\Services\EmiScheduleService;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * EMI files and the schedules they run on.
 *
 * The screen this serves is a collections desk, which is why the list carries
 * derived figures — what is outstanding, how many instalments are late, what falls
 * due next — rather than leaving the client to add up a schedule it would have to
 * fetch first.
 *
 * The distinction that runs through everything: an in-house file is money the shop
 * is owed and collecting one writes a real payment against the sale invoice; a
 * finance-company file was settled with the shop at disbursal and collecting one only
 * ticks the schedule. That logic lives in EmiScheduleService so it cannot be got
 * wrong differently in two places.
 */
class EmiPlanController extends Controller
{
    public function __construct(private readonly EmiScheduleService $schedule) {}

    public function index(Request $request)
    {
        $query = EmiPlan::query()->with([
            'customer:id,name,phone',
            'product:id,sku,name',
            'invoice:id,invoice_number,total,paid_amount,status',
            'handset:id,brand,model_name,imei_1',
            // Eager-loaded because every derived figure on a row — outstanding, paid
            // count, what is overdue, what falls due next — is read off the schedule.
            // Without this the list runs four aggregates per row.
            'instalments',
        ]);

        if ($search = $request->query('search')) {
            $digits = Imei::normalize((string) $search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('plan_number', 'like', "%{$search}%")
                    ->orWhere('loan_account_no', 'like', "%{$search}%")
                    ->orWhere('financier_name', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                if ($digits) {
                    $q->orWhere('imei', 'like', "%{$digits}%");
                }
            });
        }

        foreach (['financier', 'status', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        /**
         * Files with something late on them — the list the desk actually works.
         * Expressed as a whereHas over the schedule rather than a stored flag on the
         * file, for the same reason `overdue` is not an instalment state: it becomes
         * true at midnight and nothing is there to write it down.
         */
        if ($request->boolean('overdue')) {
            $query->where('status', 'active')
                ->whereHas('instalments', fn ($i) => $i->overdue());
        }

        if ($request->boolean('due_soon')) {
            $query->where('status', 'active')
                ->whereHas('instalments', fn ($i) => $i->dueWithin((int) $request->query('within_days', 7)));
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (EmiPlan $p) => $this->row($p))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(EmiPlan $emiPlan)
    {
        $emiPlan->load([
            'customer:id,name,phone', 'product:id,sku,name',
            'invoice:id,invoice_number,invoice_date,total,paid_amount,status',
            'handset:id,brand,model_name,imei_1,imei_2',
            'instalments',
        ]);

        return response()->json(['data' => $this->row($emiPlan, detailed: true)]);
    }

    public function store(EmiPlanRequest $request)
    {
        $data = $request->validated();

        if (! empty($data['handset_id'])) {
            $handset = Handset::find($data['handset_id']);

            if ($handset) {
                $data['product_id'] ??= $handset->product_id;
                $data['imei'] ??= $handset->imei_1;
            }
        }

        // Derived, never posted: the financed figure is what the schedule is built
        // from, and a client that computed it would be one rounding rule away from a
        // schedule that does not add up to the loan.
        $data['down_payment'] = (float) ($data['down_payment'] ?? 0);
        $data['financed_amount'] = round((float) $data['asset_value'] - $data['down_payment'], 2);

        $plan = EmiPlan::create($data + [
            'plan_number' => EmiPlan::nextNumber(),
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()?->id,
        ]);

        $this->schedule->build($plan);

        return response()->json([
            'data' => $this->row($plan->fresh(['customer', 'product', 'invoice', 'handset', 'instalments']), detailed: true),
        ], 201);
    }

    /**
     * Change a file's terms.
     *
     * The schedule is rebuilt whenever anything it derives from moves, because a file
     * whose schedule no longer matches its own tenure is worse than no file: the desk
     * chases instalments the customer was never told about. The rebuild refuses once
     * anything has been collected — see EmiScheduleService::build.
     */
    public function update(EmiPlanRequest $request, EmiPlan $emiPlan)
    {
        $data = $request->validated();
        $data['down_payment'] = (float) ($data['down_payment'] ?? 0);
        $data['financed_amount'] = round((float) $data['asset_value'] - $data['down_payment'], 2);

        $newFirstDue = date('Y-m-d', strtotime((string) $data['first_due_date']));

        $affectsSchedule = (float) $emiPlan->financed_amount !== $data['financed_amount']
            || (int) $emiPlan->tenure_months !== (int) $data['tenure_months']
            || $emiPlan->first_due_date?->toDateString() !== $newFirstDue;

        $emiPlan->update($data);

        if ($affectsSchedule) {
            try {
                $this->schedule->build($emiPlan->refresh());
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['tenure_months' => [$e->getMessage()]]);
            }
        }

        return response()->json([
            'data' => $this->row($emiPlan->fresh(['customer', 'product', 'invoice', 'handset', 'instalments']), detailed: true),
        ]);
    }

    /** Take one instalment. */
    public function collect(Request $request, EmiInstalment $instalment)
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in(['cash', 'card', 'upi'])],
            'paid_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->schedule->collect(
                $instalment,
                $data['mode'] ?? 'cash',
                $data['paid_on'] ?? null,
                $request->user()?->id,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['instalment' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->row($this->reload($instalment->plan), detailed: true),
        ]);
    }

    /** Write one instalment off. No money moved, and it does not pretend any did. */
    public function waive(Request $request, EmiInstalment $instalment)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $this->schedule->waive($instalment, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['instalment' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->row($this->reload($instalment->plan), detailed: true),
        ]);
    }

    /**
     * Mark a file gone bad, or settled early.
     *
     * `defaulted` is a statement about the customer, not about the money: pending
     * instalments stay pending, because clearing them would hide what is owed.
     * `closed` over pending rows is refused for the same reason — a foreclosure is
     * collected, not declared.
     */
    public function progress(Request $request, EmiPlan $emiPlan)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'closed', 'defaulted', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['status'] === 'closed' && $emiPlan->instalments()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['status' => [
                'Instalments are still pending on this file. Collect or waive them — closing it over the top would leave money owed and unrecorded.',
            ]]);
        }

        if ($data['status'] === 'cancelled' && $emiPlan->instalments()->where('status', 'paid')->exists()) {
            throw ValidationException::withMessages(['status' => [
                'Money has already been collected on this file, so it can no longer be cancelled. Mark it defaulted or close it.',
            ]]);
        }

        $emiPlan->forceFill([
            'status' => $data['status'],
            'closed_on' => in_array($data['status'], ['closed', 'cancelled'], true) ? now()->toDateString() : null,
            'notes' => isset($data['reason'])
                ? trim((string) $emiPlan->notes."\n".ucfirst($data['status']).': '.$data['reason'])
                : $emiPlan->notes,
        ])->save();

        return response()->json(['data' => $this->row($this->reload($emiPlan), detailed: true)]);
    }

    public function destroy(EmiPlan $emiPlan)
    {
        if ($emiPlan->instalments()->where('status', 'paid')->exists()) {
            return response()->json([
                'message' => 'Money has been collected on this file. Close or default it instead of deleting it.',
            ], 422);
        }

        $emiPlan->delete();

        return response()->json(['message' => 'Finance file deleted.']);
    }

    /** Re-read a file with everything the detailed row needs. */
    private function reload(EmiPlan $plan): EmiPlan
    {
        return $plan->fresh(['customer', 'product', 'invoice', 'handset', 'instalments']);
    }

    private function summary(): array
    {
        $activeIds = EmiPlan::where('status', 'active')->pluck('id', 'id');
        $inHouseIds = EmiPlan::where('status', 'active')->where('financier', 'in_house')->pluck('id');

        /**
         * Only in-house files are the shop's receivable. A finance-company file's
         * balance belongs to the bank, and adding the two together would report a
         * debtor book several times the real one.
         */
        $receivable = EmiInstalment::where('status', 'pending')
            ->whereIn('emi_plan_id', $inHouseIds)
            ->sum('amount');

        return [
            'active' => $activeIds->count(),
            'in_house' => $inHouseIds->count(),
            'financed' => $activeIds->count() - $inHouseIds->count(),
            'receivable' => round((float) $receivable, 2),
            'overdue_instalments' => EmiInstalment::overdue()->whereIn('emi_plan_id', $activeIds)->count(),
            'due_this_week' => EmiInstalment::dueWithin(7)->whereIn('emi_plan_id', $activeIds)->count(),
        ];
    }

    private function row(EmiPlan $p, bool $detailed = false): array
    {
        $next = $p->nextDue();

        $base = [
            'id' => $p->id,
            'plan_number' => $p->plan_number,
            'customer_id' => $p->customer_id,
            'customer' => $p->customer?->name,
            'customer_phone' => $p->customer?->phone,
            'financier' => $p->financier,
            'financier_name' => $p->financier_name,
            'loan_account_no' => $p->loan_account_no,
            // The single fact everything about the money follows from.
            'is_receivable' => $p->isReceivable(),
            'handset_id' => $p->handset_id,
            'handset' => $p->handset?->label() ?? $p->product?->name,
            'imei' => $p->handset?->imei_1 ?? $p->imei,
            'asset_value' => (float) $p->asset_value,
            'down_payment' => (float) $p->down_payment,
            'financed_amount' => (float) $p->financed_amount,
            'tenure_months' => (int) $p->tenure_months,
            'instalment_amount' => (float) $p->instalment_amount,
            'paid_count' => $p->paidCount(),
            'outstanding' => $p->outstanding(),
            'overdue_count' => $p->overdueCount(),
            'next_due_date' => $next?->due_date?->toDateString(),
            'next_due_amount' => $next ? (float) $next->amount : null,
            'status' => $p->status,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'processing_fee' => (float) $p->processing_fee,
            'interest_percent' => (float) $p->interest_percent,
            'first_due_date' => $p->first_due_date?->toDateString(),
            'invoice_id' => $p->invoice_id,
            'invoice_number' => $p->invoice?->invoice_number,
            'invoice_status' => $p->invoice?->status,
            'product_id' => $p->product_id,
            'closed_on' => $p->closed_on?->toDateString(),
            'notes' => $p->notes,
            'instalments' => $p->instalments->map(fn (EmiInstalment $i) => [
                'id' => $i->id,
                'sequence' => (int) $i->sequence,
                'due_date' => $i->due_date?->toDateString(),
                'amount' => (float) $i->amount,
                'status' => $i->status,
                'is_overdue' => $i->isOverdue(),
                'days_late' => $i->daysLate(),
                'paid_on' => $i->paid_on?->toDateString(),
                'paid_amount' => (float) $i->paid_amount,
                'payment_id' => $i->payment_id,
                'notes' => $i->notes,
            ])->values(),
        ];
    }
}
