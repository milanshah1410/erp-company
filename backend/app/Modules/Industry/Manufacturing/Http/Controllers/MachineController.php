<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ManufacturingOrder;
use App\Modules\Industry\Manufacturing\Http\Requests\MachineRequest;
use App\Modules\Industry\Manufacturing\Models\Machine;
use App\Modules\Industry\Manufacturing\Models\MachineDowntime;
use App\Modules\Industry\Manufacturing\Services\ShopFloorService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The machine master and its downtime log.
 *
 * Downtime lives here rather than in its own controller because it is not a
 * document — it is something that happens *to* a machine, always reached from
 * one, and never listed on its own except as a report. Keeping it as a
 * sub-resource means a stop cannot be recorded against a machine that does not
 * exist, which a standalone endpoint would have had to re-check.
 */
class MachineController extends Controller
{
    public function index(Request $request)
    {
        $query = Machine::query()
            ->with(['warehouse:id,name', 'department:id,name', 'operator:id,first_name,last_name'])
            ->withCount(['downtimes as open_downtimes_count' => fn ($q) => $q->whereNull('ended_at')]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('machine_type')) {
            $query->where('machine_type', $type);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('code');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (Machine $m) => $this->row($m))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(Machine $machine)
    {
        $machine->load([
            'warehouse:id,name', 'department:id,name', 'operator:id,first_name,last_name', 'asset:id,name',
        ]);

        return response()->json(['data' => $this->row($machine, withDowntimes: true)]);
    }

    public function store(MachineRequest $request)
    {
        $machine = Machine::create($request->validated() + ['created_by' => $request->user()?->id]);

        return response()->json(['data' => $this->row($machine)], 201);
    }

    public function update(MachineRequest $request, Machine $machine)
    {
        $machine->update($request->validated());

        return response()->json(['data' => $this->row($machine->fresh())]);
    }

    public function destroy(Machine $machine)
    {
        /**
         * Refused while a run is loaded on it. Deleting would null the order's
         * machine_id and quietly detach a finished run from the plant it was
         * made on — which is exactly the link a costing or a customer complaint
         * comes back to months later. Retiring keeps the history.
         */
        if ($machine->manufacturingOrders()->exists()) {
            return response()->json([
                'message' => 'This machine has production orders against it. Set its status to Retired instead.',
            ], 422);
        }

        $machine->delete();

        return response()->json(['message' => 'Machine deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Downtime
    |--------------------------------------------------------------------------
    */

    /** The stop log for one machine, newest first. */
    public function downtimes(Request $request, Machine $machine)
    {
        $query = $machine->downtimes()->with('manufacturingOrder:id,mo_number');

        if ($from = $request->query('from')) {
            $query->where('started_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('started_at', '<=', $to.' 23:59:59');
        }

        return response()->json([
            'data' => $query->limit(200)->get()->map(fn (MachineDowntime $d) => $this->downtimeRow($d))->values(),
        ]);
    }

    /**
     * Log a stop. Also moves the machine's own status, so the shop-floor list
     * answers "what can I load right now?" without opening every machine — a
     * breakdown that is only visible inside the downtime log is a breakdown the
     * planner will schedule straight into.
     */
    public function logDowntime(Request $request, Machine $machine)
    {
        $data = $request->validate([
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'reason' => ['required', Rule::in(array_keys(MachineDowntime::REASONS))],
            'manufacturing_order_id' => ['nullable', 'exists:manufacturing_orders,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $downtime = $machine->downtimes()->create([
            'manufacturing_order_id' => $data['manufacturing_order_id'] ?? null,
            'started_at' => $data['started_at'],
            'reason' => $data['reason'],
            'remarks' => $data['remarks'] ?? null,
            'reported_by' => $request->user()?->id,
        ]);

        if (! empty($data['ended_at'])) {
            // Logged after the fact — a stop that is already over.
            $downtime->close(Carbon::parse($data['ended_at']));
        } else {
            $machine->update([
                'status' => $data['reason'] === 'maintenance' ? 'maintenance' : 'breakdown',
            ]);
        }

        return response()->json(['data' => $this->downtimeRow($downtime->fresh())], 201);
    }

    /** Close an open stop and put the machine back to idle. */
    public function endDowntime(Request $request, Machine $machine, MachineDowntime $downtime)
    {
        if ($downtime->machine_id !== $machine->id) {
            return response()->json(['message' => 'That stop belongs to another machine.'], 404);
        }
        if (! $downtime->isOpen()) {
            return response()->json(['message' => 'This stop is already closed.'], 422);
        }

        $data = $request->validate([
            'ended_at' => ['nullable', 'date', 'after_or_equal:'.$downtime->started_at->toDateTimeString()],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $downtime->close(Carbon::parse($data['ended_at'] ?? now()));
        if (! empty($data['remarks'])) {
            $downtime->update(['remarks' => $data['remarks']]);
        }

        // Only lift the machine out of a stopped state; a machine someone has
        // already marked retired should not silently come back into service.
        if (in_array($machine->status, ['breakdown', 'maintenance'], true)) {
            $machine->update(['status' => 'idle']);
        }

        return response()->json(['data' => $this->downtimeRow($downtime->fresh())]);
    }

    /**
     * Utilisation over a window: available hours against downtime.
     *
     * "Available" is stated by the caller (shift hours × working days) rather
     * than guessed from a calendar the ERP does not keep. A single-shift unit
     * and a three-shift one would otherwise get the same denominator and two
     * very different truths.
     */
    public function utilisation(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'available_hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        $from = $data['from'];
        $to = $data['to'].' 23:59:59';
        // Default denominator: an 8-hour day across the window, which is the
        // most common single-shift case and is stated back in the payload so a
        // three-shift factory can see it needs overriding.
        $days = Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) + 1;
        $availableHours = (float) ($data['available_hours'] ?? $days * 8);

        $rows = Machine::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(function (Machine $m) use ($from, $to, $availableHours) {
                $down = $m->downtimeHours($from, $to);
                $up = max(0.0, $availableHours - $down);

                return [
                    'id' => $m->id,
                    'code' => $m->code,
                    'name' => $m->name,
                    'machine_type' => $m->machine_type,
                    'status' => $m->status,
                    'available_hours' => round($availableHours, 2),
                    'downtime_hours' => $down,
                    'running_hours' => round($up, 2),
                    'utilisation_percent' => $availableHours > 0
                        ? round($up / $availableHours * 100, 1)
                        : null,
                    'downtime_cost' => round($down * $m->hourlyRate(), 2),
                ];
            })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'from' => $data['from'],
                'to' => $data['to'],
                'available_hours' => round($availableHours, 2),
                'days' => $days,
                // Stated so the screen can say where the denominator came from.
                'available_hours_source' => isset($data['available_hours']) ? 'given' : 'assumed_8h_per_day',
            ],
        ]);
    }

    /**
     * Charge a production run for the hours it spent on its machine.
     *
     * A separate act from editing the order because it is arithmetic the shop
     * should be able to see and redo — hours × the machine's rate — rather than
     * a figure someone typed into a cost box. It adds to the machine bucket
     * rather than replacing it, so a run that moved between two shifts can be
     * charged twice without the second entry erasing the first.
     */
    public function chargeOrderTime(Request $request, ManufacturingOrder $manufacturingOrder, ShopFloorService $shopFloor)
    {
        $data = $request->validate([
            'hours' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $cost = $shopFloor->chargeMachineTime($manufacturingOrder, (float) $data['hours']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['hours' => [$e->getMessage()]]);
        }

        $manufacturingOrder->refresh();

        return response()->json([
            'message' => 'Machine time charged.',
            'data' => [
                'charged' => $cost,
                'machine_cost' => (float) $manufacturingOrder->machine_cost,
                'total_cost' => (float) $manufacturingOrder->total_cost,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    private function summary(): array
    {
        $counts = Machine::query()
            ->selectRaw('status, COUNT(*) as c')
            ->where('is_active', true)
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total' => Machine::where('is_active', true)->count(),
            'running' => (int) ($counts['running'] ?? 0),
            'idle' => (int) ($counts['idle'] ?? 0),
            'down' => (int) ($counts['breakdown'] ?? 0) + (int) ($counts['maintenance'] ?? 0),
            // Machines with a service date already behind them. The one number
            // on this screen that is a call to action rather than a state.
            'service_due' => Machine::where('is_active', true)
                ->whereNotNull('next_service_due')
                ->whereDate('next_service_due', '<=', now())
                ->count(),
        ];
    }

    private function row(Machine $machine, bool $withDowntimes = false): array
    {
        return [
            'id' => $machine->id,
            'code' => $machine->code,
            'name' => $machine->name,
            'machine_type' => $machine->machine_type,
            'warehouse_id' => $machine->warehouse_id,
            'warehouse' => $machine->warehouse?->name,
            'department_id' => $machine->department_id,
            'department' => $machine->department?->name,
            'asset_id' => $machine->asset_id,
            'operator_id' => $machine->operator_id,
            'operator' => $machine->operator
                ? trim($machine->operator->first_name.' '.($machine->operator->last_name ?? ''))
                : null,
            'manufacturer' => $machine->manufacturer,
            'model_number' => $machine->model_number,
            'serial_number' => $machine->serial_number,
            'installed_on' => $machine->installed_on?->toDateString(),
            'capacity_per_hour' => (float) $machine->capacity_per_hour,
            'capacity_uom' => $machine->capacity_uom,
            'hourly_cost' => (float) $machine->hourly_cost,
            'power_kw' => $machine->power_kw !== null ? (float) $machine->power_kw : null,
            'status' => $machine->status,
            'last_service_on' => $machine->last_service_on?->toDateString(),
            'next_service_due' => $machine->next_service_due?->toDateString(),
            'service_overdue' => $machine->next_service_due !== null
                && $machine->next_service_due->isPast(),
            'is_active' => (bool) $machine->is_active,
            'notes' => $machine->notes,
            'is_down' => ($machine->open_downtimes_count ?? 0) > 0,
            'downtimes' => $withDowntimes
                ? $machine->downtimes()->with('manufacturingOrder:id,mo_number')->limit(50)->get()
                    ->map(fn (MachineDowntime $d) => $this->downtimeRow($d))->values()
                : null,
        ];
    }

    private function downtimeRow(MachineDowntime $d): array
    {
        return [
            'id' => $d->id,
            'machine_id' => $d->machine_id,
            'manufacturing_order_id' => $d->manufacturing_order_id,
            'mo_number' => $d->manufacturingOrder?->mo_number,
            'started_at' => $d->started_at?->toDateTimeString(),
            'ended_at' => $d->ended_at?->toDateTimeString(),
            'minutes' => (int) $d->minutes,
            'hours' => round($d->minutes / 60, 2),
            'reason' => $d->reason,
            'reason_label' => MachineDowntime::REASONS[$d->reason] ?? $d->reason,
            'remarks' => $d->remarks,
            'is_open' => $d->isOpen(),
        ];
    }
}
