<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosCounter;
use App\Models\PosCounterSession;
use App\Services\PosCounterService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The counters a shop bills from, and the shifts worked at them.
 *
 * Two audiences on one controller, which is why the permissions split the way
 * they do. Defining a lane is a manager's act and sits on `pos-counters.*`;
 * opening and closing one is what a cashier does at the start and end of every
 * shift, and sits on the same `.create` / `.edit` grants so a till operator can
 * be given the drawer without being given the power to invent new tills.
 */
class PosCounterController extends Controller
{
    public function __construct(private readonly PosCounterService $counters) {}

    /** The lanes, each with the shift it is currently running. */
    public function index(Request $request)
    {
        $query = PosCounter::query()->with(['warehouse:id,name', 'openSession.openedBy:id,name']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $counters = $query->orderBy('code')->get();

        return response()->json(['data' => $counters->map(fn (PosCounter $c) => $this->present($c))]);
    }

    public function show(PosCounter $posCounter)
    {
        $posCounter->load(['warehouse:id,name', 'openSession.openedBy:id,name']);

        return response()->json(['data' => $this->present($posCounter)]);
    }

    public function store(Request $request)
    {
        $counter = PosCounter::create($this->validated($request));

        return response()->json(['data' => $this->present($counter->fresh(['warehouse']))], 201);
    }

    public function update(Request $request, PosCounter $posCounter)
    {
        $posCounter->update($this->validated($request, $posCounter));

        return response()->json(['data' => $this->present($posCounter->fresh(['warehouse', 'openSession']))]);
    }

    /**
     * Retire a lane.
     *
     * Refused while a shift is open — deleting the counter would orphan a drawer
     * nobody has counted. Refused too once it has traded: the sales stamped to it
     * are the record of which desk took the money, and a nulled-out counter turns
     * every one of those bills into "somewhere in the shop". Deactivate instead,
     * which is what `is_active` is for.
     */
    public function destroy(PosCounter $posCounter)
    {
        if ($posCounter->sessions()->where('status', 'open')->exists()) {
            return response()->json(['message' => 'Close the open session on this counter before deleting it.'], 422);
        }
        if ($posCounter->sessions()->exists()) {
            return response()->json([
                'message' => 'This counter has traded and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }

        $posCounter->delete();

        return response()->json(['message' => 'Counter deleted.']);
    }

    // --- Shifts ---------------------------------------------------------------

    /** Shift history, newest first. Scoped to one counter when asked. */
    public function sessions(Request $request)
    {
        $query = PosCounterSession::query()->with(['counter:id,code,name,mode', 'openedBy:id,name', 'closedBy:id,name']);

        if ($request->filled('counter_id')) {
            $query->where('counter_id', $request->integer('counter_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('opened_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('opened_at', '<=', $request->query('to'));
        }

        return response()->json([
            'data' => $query->orderByDesc('opened_at')->paginate((int) $request->query('per_page', 25)),
        ]);
    }

    /**
     * The shift this user's till is on right now.
     *
     * The POS screen calls this on load so a cashier who refreshed the page does
     * not have to be told which drawer they are in.
     */
    public function current(Request $request)
    {
        $data = $request->validate(['counter_id' => ['required', 'exists:pos_counters,id']]);

        $counter = PosCounter::findOrFail($data['counter_id']);
        $session = $counter->sessions()->where('status', 'open')->with(['openedBy:id,name'])->first();

        return response()->json([
            'data' => $session ? $this->counters->report($session->setRelation('counter', $counter)) : null,
        ]);
    }

    public function open(Request $request, PosCounter $posCounter)
    {
        $data = $request->validate([
            'opening_float' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $session = $this->counters->open(
                $posCounter,
                $request->user()?->id,
                isset($data['opening_float']) ? (float) $data['opening_float'] : null,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['counter_id' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Till opened.',
            'data' => $this->counters->report($session->load(['counter', 'openedBy'])),
        ], 201);
    }

    public function close(Request $request, PosCounterSession $posCounterSession)
    {
        $data = $request->validate([
            'declared_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $session = $this->counters->close(
                $posCounterSession,
                $request->user()?->id,
                (float) $data['declared_cash'],
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['declared_cash' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Till closed.',
            'data' => $this->counters->report($session),
        ]);
    }

    /**
     * The shift report — X while the drawer is open, Z once it is closed.
     *
     * One endpoint for both, because they are the same figures and which one you
     * get is decided by the state of the session, not by the caller.
     */
    public function report(PosCounterSession $posCounterSession)
    {
        $posCounterSession->load(['counter', 'openedBy', 'closedBy']);

        return response()->json(['data' => $this->counters->report($posCounterSession)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PosCounter $counter): array
    {
        $session = $counter->relationLoaded('openSession') ? $counter->openSession : null;

        return [
            'id' => $counter->id,
            'code' => $counter->code,
            'name' => $counter->name,
            'mode' => $counter->mode,
            'location' => $counter->location,
            'warehouse_id' => $counter->warehouse_id,
            'warehouse_name' => $counter->warehouse?->name,
            'default_float' => (float) $counter->default_float,
            'printer_device_id' => $counter->printer_device_id,
            'is_active' => (bool) $counter->is_active,
            // Flattened rather than nested: every screen that lists counters asks
            // "is this lane open, since when, and by whom" and none of them wants
            // to reach into a relation for it.
            'session_id' => $session?->id,
            'is_open' => $session !== null,
            'opened_at' => $session?->opened_at?->toDateTimeString(),
            'opened_by' => $session?->openedBy?->name,
            'opening_float' => $session ? (float) $session->opening_float : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PosCounter $counter = null): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('pos_counters', 'code')
                    ->where('tenant_id', $request->user()?->tenant_id)
                    ->ignore($counter?->id),
            ],
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['nullable', Rule::in(['attended', 'self_checkout'])],
            'location' => ['nullable', 'string', 'max:120'],
            'default_float' => ['nullable', 'numeric', 'min:0'],
            'printer_device_id' => ['nullable', 'exists:devices,id'],
            'is_active' => ['boolean'],
        ]);
    }
}
