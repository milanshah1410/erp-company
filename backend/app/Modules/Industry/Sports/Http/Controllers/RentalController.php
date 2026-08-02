<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Sports\Http\Requests\RentalRequest;
use App\Modules\Industry\Sports\Http\Requests\RentalReturnRequest;
use App\Modules\Industry\Sports\Models\Rental;
use App\Modules\Industry\Sports\Models\RentalItem;
use App\Modules\Industry\Sports\Services\RentalPricingService;
use App\Modules\Industry\Sports\Services\RentalService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The hire desk.
 *
 * A board to be worked, not a register to be browsed. The default view is
 * everything still out or booked, soonest first, with overdue in red — because
 * the whole of this trade's operational risk is kit that has not come back, and a
 * screen that opens on a lifetime list of contracts hides exactly that.
 *
 * Money is quoted live rather than stored. A contract that is out has a bill that
 * grows every hour it stays out, so `quote` prices it to the moment on every read
 * — a stored figure would be a number the counter reads out and the customer is
 * then charged something different from.
 *
 * Every refusal from the service layer comes back as a 422 with the sentence the
 * service wrote. Those sentences are the product: "SPB-07 is out on SPR-000014,
 * due back Sun 9 Aug, 18:00" is something a counter can act on, and "unavailable"
 * is not.
 */
class RentalController extends Controller
{
    public function __construct(
        private readonly RentalService $rentals,
        private readonly RentalPricingService $pricing,
    ) {}

    public function index(Request $request)
    {
        $query = Rental::query()->with(['customer:id,name,phone', 'items.asset:id,asset_code,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('rental_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('items.asset', fn ($a) => $a->where('asset_code', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } elseif (! $request->boolean('all')) {
            // The desk's default: what is still the shop's problem.
            $query->holding();
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        // Soonest promise first — the order the desk works in. Settled contracts
        // have nothing to chase, so they fall to the newest-first pile.
        $query->orderByRaw("FIELD(status, 'out', 'booked', 'returned', 'closed', 'cancelled')")
            ->orderBy('due_at')
            ->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (Rental $r) => $this->row($r))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(Rental $rental)
    {
        $rental->load([
            'customer:id,name,phone,email',
            'warehouse:id,name',
            'items.asset:id,asset_code,name,category,serial_no',
            'invoice:id,invoice_number,total,paid_amount,status',
            'salesOrder:id,so_number',
        ]);

        return response()->json(['data' => $this->row($rental, detailed: true)]);
    }

    public function store(RentalRequest $request)
    {
        try {
            $rental = $this->rentals->create($request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($rental, detailed: true)], 201);
    }

    public function update(RentalRequest $request, Rental $rental)
    {
        try {
            $rental = $this->rentals->update($rental, $request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($rental, detailed: true)]);
    }

    /** Hand the kit over. */
    public function issue(Request $request, Rental $rental)
    {
        $data = $request->validate(['issued_at' => ['nullable', 'date', 'before_or_equal:now']]);

        try {
            $rental = $this->rentals->issue(
                $rental,
                isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->row($rental, detailed: true),
            'message' => "Handed over on {$rental->rental_number}. Due back ".$rental->due_at->format('D j M, H:i').'.',
        ]);
    }

    /** Push the promise out on a live hire. */
    public function extend(Request $request, Rental $rental)
    {
        $data = $request->validate(['due_at' => ['required', 'date']]);

        try {
            $rental = $this->rentals->extend($rental, $data['due_at']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->row($rental, detailed: true),
            'message' => 'Extended to '.$rental->due_at->format('D j M, H:i').'.',
        ]);
    }

    /** Take kit back — some of it, or all of it. */
    public function returnItems(RentalReturnRequest $request, Rental $rental)
    {
        $data = $request->validated();

        try {
            $rental = $this->rentals->returnItems(
                $rental,
                $data['items'],
                isset($data['returned_at']) ? Carbon::parse($data['returned_at']) : null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $outstanding = $rental->outstandingItems()->count();

        return response()->json([
            'data' => $this->row($rental, detailed: true),
            'message' => $outstanding > 0
                ? "Taken back. {$outstanding} item(s) still out on {$rental->rental_number}."
                : "Everything back on {$rental->rental_number}. Settle it to close the hire.",
        ]);
    }

    /** Bill it and deal with the deposit. */
    public function close(Request $request, Rental $rental)
    {
        $data = $request->validate([
            // Null means "keep as much as the bill needs", which is what a counter
            // does without thinking about it. Zero refunds the deposit whole.
            'apply_deposit' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $invoice = $this->rentals->close(
                $rental,
                array_key_exists('apply_deposit', $data) ? (float) $data['apply_deposit'] : null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rental = $rental->fresh(['items.asset', 'customer', 'invoice']);
        $refund = (float) $rental->deposit_refunded;

        return response()->json([
            'data' => $this->row($rental, detailed: true),
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => (float) $invoice->total,
                'paid_amount' => (float) $invoice->paid_amount,
                'status' => $invoice->status,
            ] : null,
            'message' => $refund > 0
                ? 'Settled. Refund '.number_format($refund, 2).' of the deposit.'
                : 'Settled.',
        ]);
    }

    public function cancel(Request $request, Rental $rental)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $rental = $this->rentals->cancel($rental, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($rental->fresh(['items.asset', 'customer']), detailed: true)]);
    }

    public function destroy(Rental $rental)
    {
        /**
         * Only a contract that never happened. A hire that went out is the record
         * of kit having left the shop, and deleting it removes the only evidence
         * of where a bike went — cancel it, or settle it.
         */
        if (! in_array($rental->status, ['booked', 'cancelled'], true)) {
            return response()->json([
                'message' => "Hire {$rental->rental_number} has been out. Cancel or settle it — it cannot be deleted.",
            ], 422);
        }

        $rental->delete();

        return response()->json(['message' => 'Hire deleted.']);
    }

    private function summary(): array
    {
        $now = now();

        return [
            'booked' => Rental::query()->where('status', 'booked')->count(),
            'out' => Rental::query()->where('status', 'out')->count(),
            'overdue' => Rental::query()->overdue($now)->count(),
            // Back today and waiting to be settled — the desk's end-of-day pile.
            'awaiting_settlement' => Rental::query()->where('status', 'returned')->count(),
            'due_today' => Rental::query()
                ->where('status', 'out')
                ->whereDate('due_at', $now->toDateString())
                ->count(),
            // What the shop is holding of other people's money. A liability, and
            // the number an owner is most surprised to see the size of.
            'deposits_held' => round(
                (float) Rental::query()->whereIn('status', ['out', 'returned'])->sum('deposit_amount'),
                2,
            ),
        ];
    }

    private function row(Rental $rental, bool $detailed = false): array
    {
        $rental->loadMissing(['customer', 'items.asset']);

        $base = [
            'id' => $rental->id,
            'rental_number' => $rental->rental_number,
            'customer' => $rental->customer?->name,
            'customer_id' => $rental->customer_id,
            'customer_phone' => $rental->customer?->phone,
            'status' => $rental->status,
            'rate_basis' => $rental->rate_basis,
            'booked_from' => $rental->booked_from?->toDateTimeString(),
            'issued_at' => $rental->issued_at?->toDateTimeString(),
            'due_at' => $rental->due_at?->toDateTimeString(),
            'returned_at' => $rental->returned_at?->toDateTimeString(),
            'grace_hours' => (int) $rental->grace_hours,
            'is_overdue' => $rental->isOverdue(),
            'hours_late' => $rental->hoursLate(),
            'item_count' => $rental->items->count(),
            'outstanding_count' => $rental->outstandingItems()->count(),
            'assets' => $rental->items->map(fn (RentalItem $i) => $i->asset?->asset_code)->filter()->values(),
            'deposit_amount' => (float) $rental->deposit_amount,
            'total' => (float) $rental->total,
            'is_billed' => $rental->isBilled(),
        ];

        if (! $detailed) {
            return $base;
        }

        /**
         * Priced to this moment, not read off the header. A hire that is out has a
         * bill that grows every hour, and the counter needs the number as it is
         * now — see the class note.
         */
        $quote = $this->pricing->quote($rental);

        return $base + [
            'warehouse_id' => $rental->warehouse_id,
            'id_proof_type' => $rental->id_proof_type,
            'id_proof_number' => $rental->id_proof_number,
            'discount_type' => $rental->discount_type,
            'discount_value' => (float) $rental->discount_value,
            'discount_amount' => (float) $rental->discount_amount,
            'hire_total' => (float) $rental->hire_total,
            'late_total' => (float) $rental->late_total,
            'damage_total' => (float) $rental->damage_total,
            'tax_amount' => (float) $rental->tax_amount,
            'round_off' => (float) $rental->round_off,
            'deposit_applied' => (float) $rental->deposit_applied,
            'deposit_refunded' => (float) $rental->deposit_refunded,
            'quote' => $quote,
            'notes' => $rental->notes,
            'invoice_number' => $rental->invoice?->invoice_number,
            'so_number' => $rental->salesOrder?->so_number,
            'items' => $rental->items->map(function (RentalItem $item) use ($rental) {
                $live = $item->isReturned() ? null : $this->pricing->priceLine($rental, $item);

                return [
                    'id' => $item->id,
                    'asset_id' => $item->asset_id,
                    'asset_code' => $item->asset?->asset_code,
                    'name' => $item->asset?->name,
                    'serial_no' => $item->asset?->serial_no,
                    'rate' => (float) $item->rate,
                    'late_rate' => $item->lateRate(),
                    'deposit' => (float) $item->deposit,
                    'condition_out' => $item->condition_out,
                    'condition_in' => $item->condition_in,
                    'returned_at' => $item->returned_at?->toDateTimeString(),
                    'hire_units' => (float) ($live['hire_units'] ?? $item->hire_units),
                    'hire_charge' => (float) ($live['hire_charge'] ?? $item->hire_charge),
                    'late_units' => (float) ($live['late_units'] ?? $item->late_units),
                    'late_charge' => (float) ($live['late_charge'] ?? $item->late_charge),
                    'damage_charge' => (float) $item->damage_charge,
                    'damage_note' => $item->damage_note,
                    // Flags the line as a running figure rather than a settled one,
                    // so the screen can say so instead of implying a final bill.
                    'is_live' => $live !== null,
                ];
            })->values(),
        ];
    }
}
