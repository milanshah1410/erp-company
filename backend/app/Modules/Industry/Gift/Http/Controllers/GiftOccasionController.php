<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Gift\Http\Requests\GiftOccasionRequest;
use App\Modules\Industry\Gift\Models\GiftOccasion;
use App\Modules\Industry\Gift\Services\GiftOccasionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The occasion board — every date this shop's customers buy for.
 *
 * A gift shop's repeat business is one sentence: "your wife's birthday is in nine
 * days, shall I put something aside?" Everything on this screen serves that
 * sentence, which is why the list is sorted by *next occurrence* rather than by
 * anything stored, and why the two facts that make the call work — last year's
 * gift and this year's budget — are on the row and not behind a click.
 *
 * Sorting and windowing happen in PHP. "Soonest" is a day-and-month comparison
 * that rolls over the new year, and no portable SQL for it survives three database
 * drivers; an occasion register is a few hundred rows per shop, so this is the
 * cheap side of that trade-off. Filters that *are* expressible in SQL (customer,
 * kind, active) still run there, so the set being sorted stays small.
 */
class GiftOccasionController extends Controller
{
    public function __construct(private readonly GiftOccasionService $occasions) {}

    public function index(Request $request)
    {
        $query = GiftOccasion::query()->with('customer:id,code,name,phone,email');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('person_name', 'like', "%{$search}%")
                    ->orWhere('relation', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('last_gift', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['customer_id', 'occasion', 'source'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $today = Carbon::today();
        $rows = $query->get();

        // The board's own window: what is coming up inside N days. Absent, the
        // whole register is listed — which is what a search is for.
        if ($request->filled('within_days')) {
            $within = max(0, (int) $request->query('within_days'));
            $rows = $rows->filter(function (GiftOccasion $o) use ($within, $today) {
                $days = $o->daysUntil($today);

                return $days !== null && $days >= 0 && $days <= $within;
            });
        }

        if ($request->boolean('reminder_due')) {
            $rows = $rows->filter(fn (GiftOccasion $o) => $o->reminderDue($today));
        }

        $rows = $rows
            ->sortBy(fn (GiftOccasion $o) => $o->daysUntil($today) ?? PHP_INT_MAX)
            ->values()
            ->map(fn (GiftOccasion $o) => $this->row($o, $today));

        return response()->json([
            'data' => $rows,
            'summary' => $this->summary($today),
        ]);
    }

    /** The next month, for the dashboard-style panel the counter works from. */
    public function upcoming(Request $request)
    {
        $within = min(365, max(1, (int) $request->query('within_days', 30)));
        $today = Carbon::today();

        return response()->json([
            'data' => $this->occasions->upcoming($within, $today)
                ->map(fn (GiftOccasion $o) => $this->row($o, $today))->values(),
            'summary' => $this->summary($today),
            'within_days' => $within,
        ]);
    }

    public function show(GiftOccasion $occasion)
    {
        $occasion->load('customer:id,code,name,phone,email', 'creator:id,name');

        return response()->json(['data' => $this->row($occasion, Carbon::today(), detailed: true)]);
    }

    public function store(GiftOccasionRequest $request)
    {
        $occasion = GiftOccasion::create($request->validated() + [
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(
            ['data' => $this->row($occasion->fresh('customer'), Carbon::today(), detailed: true)],
            201,
        );
    }

    public function update(GiftOccasionRequest $request, GiftOccasion $occasion)
    {
        $occasion->update($request->validated());

        return response()->json(
            ['data' => $this->row($occasion->fresh('customer'), Carbon::today(), detailed: true)],
        );
    }

    public function destroy(GiftOccasion $occasion)
    {
        $occasion->delete();

        return response()->json(['message' => 'Occasion removed.']);
    }

    /**
     * Remind now, rather than waiting for tonight's run.
     *
     * The counter's manual override, for the customer who is standing there or the
     * date somebody spotted late. Stamps `last_reminded_on` exactly as the
     * scheduled run does, so a hand-sent reminder is not sent again automatically
     * the same week.
     */
    public function remind(GiftOccasion $occasion)
    {
        $result = $this->occasions->remind($occasion);

        return response()->json([
            'data' => $this->row($occasion->fresh('customer'), Carbon::today(), detailed: true),
            'message' => $result['sms']
                ? 'Reminder sent to the customer, and the counter was notified.'
                : ($result['notified'] > 0
                    ? 'The counter was notified. No SMS went out (either switched off for this occasion, no gateway configured, or no phone number).'
                    : 'Nobody to notify — no active sales staff hold a role that receives occasion alerts.'),
        ]);
    }

    /**
     * Lift birthdays and anniversaries out of the customer master.
     *
     * A shop that has been keying dates onto customer records for two years should
     * not key them again. Idempotent, and it never touches a row someone has
     * edited by hand.
     */
    public function import(Request $request)
    {
        $result = $this->occasions->importFromCustomers(
            $request->user()?->id,
            min(90, max(0, (int) $request->input('remind_days_before', 7))),
        );

        return response()->json([
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'message' => $result['created'] > 0
                ? "Added {$result['created']} occasion(s) from the customer master; {$result['skipped']} already on the board."
                : 'Nothing new to add — every customer date is already on the board.',
        ]);
    }

    private function summary(Carbon $today): array
    {
        $all = GiftOccasion::query()->active()->get();

        $within = fn (int $days) => $all->filter(function (GiftOccasion $o) use ($days, $today) {
            $d = $o->daysUntil($today);

            return $d !== null && $d >= 0 && $d <= $days;
        })->count();

        return [
            'tracked' => GiftOccasion::count(),
            'active' => $all->count(),
            'today' => $within(0),
            'next_7_days' => $within(7),
            'next_30_days' => $within(30),
            // What the shop owes a call on right now. The number the screen leads
            // with, because it is the only one that is a to-do rather than a fact.
            'reminder_due' => $all->filter(fn (GiftOccasion $o) => $o->reminderDue($today))->count(),
        ];
    }

    private function row(GiftOccasion $o, Carbon $today, bool $detailed = false): array
    {
        $next = $o->nextOccurrence($today);

        $base = [
            'id' => $o->id,
            'customer_id' => $o->customer_id,
            'customer' => $o->customer?->name,
            'customer_phone' => $o->customer?->phone,
            'person_name' => $o->person_name,
            'person' => $o->personLabel(),
            'relation' => $o->relation,
            'occasion' => $o->occasion,
            'title' => $o->title,
            'occasion_label' => $o->occasionLabel(),
            'occasion_date' => $o->occasion_date?->toDateString(),
            'is_recurring' => (bool) $o->is_recurring,
            // All three derived on read. A stored "next date" needs a nightly job
            // to stay true and is wrong every day between that job failing and
            // somebody noticing.
            'next_occurrence' => $next?->toDateString(),
            'days_until' => $o->daysUntil($today),
            'remind_on' => $o->remindOn($today)?->toDateString(),
            'reminder_due' => $o->reminderDue($today),
            'remind_days_before' => (int) $o->remind_days_before,
            'budget' => $o->budget !== null ? (float) $o->budget : null,
            'last_gift' => $o->last_gift,
            'last_gifted_on' => $o->last_gifted_on?->toDateString(),
            'last_reminded_on' => $o->last_reminded_on?->toDateString(),
            'notify_customer' => (bool) $o->notify_customer,
            'is_active' => (bool) $o->is_active,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'preferences' => $o->preferences,
            'notes' => $o->notes,
            'source' => $o->source,
            'customer_email' => $o->customer?->email,
            'created_by' => $o->relationLoaded('creator') ? $o->creator?->name : null,
        ];
    }
}
