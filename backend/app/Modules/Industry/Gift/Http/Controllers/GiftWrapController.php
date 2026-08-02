<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Gift\Http\Requests\GiftWrapRequest;
use App\Modules\Industry\Gift\Models\GiftWrap;
use App\Modules\Industry\Gift\Models\GiftWrapOption;
use App\Modules\Industry\Gift\Services\GiftWrapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The wrap desk.
 *
 * Built around the queue, because at Diwali the queue *is* the department: what
 * is waiting, what was promised for when, and what is already late. So the list
 * defaults to open parcels in promise order and the summary counts the only two
 * numbers anyone shouts across a shop — how many left to do, and how many of
 * those are late.
 *
 * The charge is snapshotted from the option at creation and never recomputed.
 * Wrapping is billed as an ordinary line on the sale (the option's service
 * product), so what this register keeps is the desk's own record of what applied
 * on the day — repricing paper in November must not rewrite October's parcels.
 */
class GiftWrapController extends Controller
{
    public function __construct(private readonly GiftWrapService $wraps) {}

    public function index(Request $request)
    {
        $query = GiftWrap::query()->with([
            'option:id,code,name,kind,charge,turnaround_minutes',
            'customer:id,code,name,phone',
            'invoice:id,invoice_number,invoice_date,total',
            'salesOrder:id,order_number,order_date',
            'occasion:id,person_name,relation,occasion,title,occasion_date',
            'wrapper:id,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('wrap_number', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('sender_name', 'like', "%{$search}%")
                    ->orWhere('recipient_phone', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'wrap_option_id', 'customer_id', 'invoice_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        /**
         * The desk's own view, and the default: everything still to do, soonest
         * promise first, unpromised parcels behind them. A wrap desk opened onto
         * a reverse-chronological list of everything ever wrapped is a wrap desk
         * that has to search before it can work.
         */
        if ($request->boolean('queue', ! $request->filled('status'))) {
            $query->queue();
        } else {
            $query->orderByDesc('id');
        }

        if ($request->boolean('late')) {
            $query->whereIn('status', GiftWrap::OPEN_STATUSES)
                ->whereNotNull('promised_at')
                ->where('promised_at', '<', now());
        }

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (GiftWrap $w) => $this->row($w))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(GiftWrap $wrap)
    {
        $wrap->load([
            'option', 'customer:id,code,name,phone,email',
            'invoice:id,invoice_number,invoice_date,total',
            'salesOrder:id,order_number,order_date',
            'occasion:id,customer_id,person_name,relation,occasion,title,occasion_date',
            'warehouse:id,name', 'wrapper:id,name', 'creator:id,name',
        ]);

        return response()->json(['data' => $this->row($wrap, detailed: true)]);
    }

    public function store(GiftWrapRequest $request)
    {
        $data = $request->validated();
        $option = GiftWrapOption::findOrFail($data['wrap_option_id']);

        $wrap = GiftWrap::create($data + [
            'wrap_number' => GiftWrap::nextNumber(),
            // Snapshots. See the class docblock.
            'wrap_label' => $option->name,
            'charge' => (float) $option->charge,
            /**
             * When the desk did not promise a time and the option quotes a
             * turnaround, promise that. A parcel with no promised time at all is
             * the one that gets forgotten at the bottom of the pile.
             */
            'promised_at' => $data['promised_at']
                ?? ($option->turnaround_minutes ? now()->addMinutes((int) $option->turnaround_minutes) : null),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($wrap->fresh($this->relations()), detailed: true)], 201);
    }

    public function update(GiftWrapRequest $request, GiftWrap $wrap)
    {
        $data = $request->validated();

        /**
         * Changing the wrapping re-snapshots the label and charge — but only while
         * the parcel is still open. Once it is wrapped the paper is cut and the
         * material has been drawn down; letting someone swap the option afterwards
         * would rewrite what was charged for work already done.
         */
        if (isset($data['wrap_option_id']) && $wrap->isOpen()
            && (int) $data['wrap_option_id'] !== (int) $wrap->wrap_option_id) {
            $option = GiftWrapOption::findOrFail($data['wrap_option_id']);
            $data['wrap_label'] = $option->name;
            $data['charge'] = (float) $option->charge;
        } elseif (isset($data['wrap_option_id']) && ! $wrap->isOpen()) {
            unset($data['wrap_option_id']);
        }

        $wrap->update($data);

        return response()->json(['data' => $this->row($wrap->fresh($this->relations()), detailed: true)]);
    }

    /**
     * Done — and the material it took comes off the shelf.
     *
     * Wrapped once, ever: GiftWrapService holds the guard, so a double tap on a
     * busy evening cannot eat two sheets of paper.
     */
    public function wrap(Request $request, GiftWrap $wrap)
    {
        if (! $wrap->isOpen()) {
            return response()->json([
                'message' => 'This parcel is already '.$wrap->status.'.',
            ], 422);
        }

        $posted = DB::transaction(
            fn () => $this->wraps->markWrapped($wrap, $request->user()?->id),
        );

        return response()->json([
            'data' => $this->row($wrap->fresh($this->relations()), detailed: true),
            'message' => $posted > 0
                ? 'Parcel wrapped, and the wrapping material was taken off stock.'
                : 'Parcel wrapped.',
        ]);
    }

    /** Handed over. Nothing moves — the goods left stock when they were sold. */
    public function collect(Request $request, GiftWrap $wrap)
    {
        if ($wrap->status === 'cancelled') {
            return response()->json(['message' => 'This parcel was cancelled.'], 422);
        }

        /**
         * A parcel collected straight off the counter was still wrapped, so this
         * posts the material first rather than skipping it. Otherwise a shop that
         * only ever presses "collected" would consume no paper at all.
         */
        DB::transaction(function () use ($request, $wrap) {
            if ($wrap->isOpen()) {
                $this->wraps->markWrapped($wrap, $request->user()?->id);
            }

            $this->wraps->markCollected($wrap);
        });

        return response()->json([
            'data' => $this->row($wrap->fresh($this->relations()), detailed: true),
            'message' => 'Parcel collected.',
        ]);
    }

    public function cancel(Request $request, GiftWrap $wrap)
    {
        if ($wrap->status === 'collected') {
            return response()->json(['message' => 'This parcel has already been collected.'], 422);
        }

        $this->wraps->cancel($wrap, $request->string('reason')->toString() ?: null);

        return response()->json([
            'data' => $this->row($wrap->fresh($this->relations()), detailed: true),
            // Said plainly, because the alternative — quietly crediting paper back
            // to stock — would make the stock file tidier and less true.
            'message' => $wrap->materialPosted()
                ? 'Parcel cancelled. The wrapping material it already used stays consumed.'
                : 'Parcel cancelled.',
        ]);
    }

    public function destroy(GiftWrap $wrap)
    {
        /**
         * A parcel that consumed material is a stock document: the ledger rows
         * point at it by id, and deleting it would leave a movement nobody can
         * explain. Cancel instead.
         */
        if ($wrap->materialPosted()) {
            return response()->json([
                'message' => 'This parcel has stock movements against it. Cancel it instead of deleting it.',
            ], 422);
        }

        $wrap->delete();

        return response()->json(['message' => 'Parcel removed.']);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['option', 'customer', 'invoice', 'salesOrder', 'occasion', 'warehouse', 'wrapper'];
    }

    private function summary(): array
    {
        $open = GiftWrap::whereIn('status', GiftWrap::OPEN_STATUSES);

        return [
            'pending' => (clone $open)->count(),
            'late' => (clone $open)->whereNotNull('promised_at')->where('promised_at', '<', now())->count(),
            'due_today' => (clone $open)->whereDate('promised_at', today())->count(),
            'wrapped' => GiftWrap::where('status', 'wrapped')->count(),
            'collected_today' => GiftWrap::where('status', 'collected')
                ->whereDate('collected_at', today())->count(),
            // What the desk charged today. The only money question this register
            // answers, and the reason a shop can tell whether wrapping pays.
            'charged_today' => (float) GiftWrap::whereDate('created_at', today())->sum('charge'),
        ];
    }

    private function row(GiftWrap $w, bool $detailed = false): array
    {
        $base = [
            'id' => $w->id,
            'wrap_number' => $w->wrap_number,
            'wrap_option_id' => $w->wrap_option_id,
            'wrap_label' => $w->wrap_label ?: $w->option?->name,
            'kind' => $w->option?->kind,
            'charge' => (float) $w->charge,
            'customer_id' => $w->customer_id,
            'customer' => $w->customer?->name,
            'recipient_name' => $w->recipient_name,
            'sender_name' => $w->sender_name,
            'hide_prices' => (bool) $w->hide_prices,
            'status' => $w->status,
            'promised_at' => $w->promised_at?->toIso8601String(),
            // Derived on read: a parcel promised for six is late at ten past,
            // whether or not anything has swept the table.
            'is_late' => $w->isLate(),
            'wrapped_at' => $w->wrapped_at?->toIso8601String(),
            'wrapped_by' => $w->wrapper?->name,
            'collected_at' => $w->collected_at?->toIso8601String(),
            'invoice_id' => $w->invoice_id,
            'invoice_number' => $w->invoice?->invoice_number,
            'occasion_id' => $w->occasion_id,
            'material_posted' => $w->materialPosted(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'recipient_phone' => $w->recipient_phone,
            'message' => $w->message,
            'sales_order_id' => $w->sales_order_id,
            'sales_order_number' => $w->salesOrder?->order_number,
            'occasion' => $w->occasion ? [
                'id' => $w->occasion->id,
                'label' => $w->occasion->occasionLabel(),
                'person' => $w->occasion->personLabel(),
                'date' => $w->occasion->occasion_date?->toDateString(),
            ] : null,
            'warehouse_id' => $w->warehouse_id,
            'warehouse' => $w->warehouse?->name,
            'material' => $w->option?->material?->name,
            'notes' => $w->notes,
            'created_by' => $w->creator?->name,
            'created_at' => $w->created_at?->toIso8601String(),
        ];
    }
}
