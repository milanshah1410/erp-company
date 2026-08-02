<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Services;

use App\Modules\Industry\Sports\Models\Rental;
use App\Modules\Industry\Sports\Models\RentalAsset;
use App\Modules\Industry\Sports\Models\RentalItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The life of a hire: booked, handed over, brought back, settled.
 *
 * Every transition that can be got wrong lives here rather than in the
 * controller, because the ways a hire desk goes wrong are all the same shape —
 * something was promised twice, something went out without being written down, or
 * something came back and nobody told the fleet. Each of those is a rule in this
 * file and a refusal at the door.
 *
 * The four decisions worth knowing about:
 *
 * **A booking holds the kit.** From the moment it is written, not from the moment
 * it is collected. That is the entire purpose of taking a booking, and the reason
 * `RentalAvailabilityService` reads bookings and hires as one.
 *
 * **Returns are per line and partial by default.** Six bikes out, four back
 * Sunday, two back Monday. A return recorded on the header would either overcharge
 * the four or let the two off, and would leave four bikes marked unavailable for
 * Monday's customer.
 *
 * **A line is priced once, at its own return.** Not at settlement. Otherwise the
 * four bikes that came back on time are quietly charged Monday's late fee, which
 * is the arithmetic error a hire customer is most likely to spot and least likely
 * to forgive.
 *
 * **A hire that is out cannot be cancelled.** Cancelling a booking is housekeeping;
 * "cancelling" a hire that is physically in somebody's car is a euphemism for
 * losing track of it. It comes back and is settled, or it is returned as lost and
 * charged for.
 */
class RentalService
{
    public function __construct(
        private readonly RentalAvailabilityService $availability,
        private readonly RentalPricingService $pricing,
        private readonly RentalBillingService $billing,
    ) {}

    /**
     * Write a contract — a forward booking, or a hire about to be handed over.
     *
     * @param  array{customer_id: int, warehouse_id?: int|null, rate_basis?: string, booked_from?: string|null, due_at: string, grace_hours?: int, id_proof_type?: string|null, id_proof_number?: string|null, discount_type?: string|null, discount_value?: float, notes?: string|null, assets: list<array{asset_id: int, condition_out?: string}>}  $data
     */
    public function create(array $data, ?int $userId = null): Rental
    {
        $basis = $data['rate_basis'] ?? 'daily';
        $from = $this->at($data['booked_from'] ?? null) ?? now();
        $due = $this->at($data['due_at']);

        if ($due === null || $due->lte($from)) {
            throw new RuntimeException('The kit has to come back after it goes out — check the dates.');
        }

        $assets = $this->assetsFor($data['assets'] ?? []);
        $this->assertAvailable($assets, $from, $due, $basis);

        return DB::transaction(function () use ($data, $assets, $basis, $from, $due, $userId) {
            $rental = Rental::create([
                'rental_number' => Rental::nextNumber(),
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => 'booked',
                'rate_basis' => $basis,
                'booked_from' => $from,
                'due_at' => $due,
                'grace_hours' => (int) ($data['grace_hours'] ?? 0),
                'id_proof_type' => $data['id_proof_type'] ?? null,
                'id_proof_number' => $data['id_proof_number'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->writeLines($rental, $assets, $data['assets']);

            return $rental->fresh(['items.asset', 'customer']);
        });
    }

    /**
     * Change a booking that has not gone out yet.
     *
     * Deliberately refused once anything has been handed over. An `out` contract
     * whose lines can still be edited is how kit gets removed from a hire it is
     * physically on — and the ERP's usual delete-and-recreate line pattern would
     * do exactly that. Extending a live hire is a different operation with its own
     * door: see extend().
     */
    public function update(Rental $rental, array $data, ?int $userId = null): Rental
    {
        if ($rental->status !== 'booked') {
            throw new RuntimeException(
                "Hire {$rental->rental_number} has already been handed over. Extend it or take it back — it cannot be re-written.",
            );
        }

        $basis = $data['rate_basis'] ?? $rental->rate_basis;
        $from = $this->at($data['booked_from'] ?? null) ?? $rental->booked_from ?? now();
        $due = $this->at($data['due_at'] ?? null) ?? $rental->due_at;

        if ($due->lte($from)) {
            throw new RuntimeException('The kit has to come back after it goes out — check the dates.');
        }

        $assets = array_key_exists('assets', $data)
            ? $this->assetsFor($data['assets'])
            : null;

        if ($assets !== null) {
            // Its own lines must not count against it, or a booking could never
            // be edited without appearing to clash with itself.
            $this->assertAvailable($assets, $from, $due, $basis, $rental->id);
        }

        return DB::transaction(function () use ($rental, $data, $assets, $basis, $from, $due) {
            $rental->fill([
                'customer_id' => $data['customer_id'] ?? $rental->customer_id,
                'warehouse_id' => $data['warehouse_id'] ?? $rental->warehouse_id,
                'rate_basis' => $basis,
                'booked_from' => $from,
                'due_at' => $due,
                'grace_hours' => (int) ($data['grace_hours'] ?? $rental->grace_hours),
                'id_proof_type' => $data['id_proof_type'] ?? $rental->id_proof_type,
                'id_proof_number' => $data['id_proof_number'] ?? $rental->id_proof_number,
                'discount_type' => $data['discount_type'] ?? $rental->discount_type,
                'discount_value' => $data['discount_value'] ?? $rental->discount_value,
                'notes' => $data['notes'] ?? $rental->notes,
            ])->save();

            if ($assets !== null) {
                // Safe here and only here: nothing on a booking has left the shop,
                // so no line carries a fact that re-creating it would destroy.
                $rental->items()->delete();
                $this->writeLines($rental, $assets, $data['assets']);
            }

            return $rental->fresh(['items.asset', 'customer']);
        });
    }

    /**
     * Hand the kit over.
     *
     * Two things are checked at this door and nowhere else, because this is the
     * last moment either can be checked: that the counter has seen identification
     * where the asset demands it, and that nothing on the contract was quietly
     * taken by another hire in the days since the booking was written.
     */
    public function issue(Rental $rental, ?Carbon $at = null, ?int $userId = null): Rental
    {
        if ($rental->status !== 'booked') {
            throw new RuntimeException("Hire {$rental->rental_number} is {$rental->status} — only a booking can be handed over.");
        }

        $rental->loadMissing('items.asset');

        if ($rental->items->isEmpty()) {
            throw new RuntimeException("Hire {$rental->rental_number} has no kit on it.");
        }

        $needsId = $rental->items->contains(fn (RentalItem $i) => (bool) $i->asset?->requires_id_proof);

        if ($needsId && ! $rental->id_proof_number) {
            throw new RuntimeException(
                'This kit is not handed over without identification. Record the ID type and number first.',
            );
        }

        $at ??= now();

        /**
         * Re-checked at the moment of handover. A booking written a fortnight ago
         * says nothing about what happened to the fleet since — an asset may have
         * gone to the bench, been written off, or been handed to somebody else on
         * a walk-in hire. Discovering that as the customer picks the bike up is
         * unpleasant; discovering it after they have gone is worse.
         */
        foreach ($rental->items as $item) {
            $asset = $item->asset;

            if (! $asset) {
                continue;
            }

            $refusal = $this->availability->refusalFor(
                $asset,
                $at,
                $rental->due_at,
                $rental->rate_basis,
                $rental->id,
            );

            if ($refusal) {
                throw new RuntimeException($refusal);
            }
        }

        return DB::transaction(function () use ($rental, $at) {
            $rental->forceFill([
                'status' => 'out',
                'issued_at' => $at,
                // The deposit becomes real at handover, not at booking: it is the
                // sum of what was actually taken for the kit that actually left.
                'deposit_amount' => round($rental->items->sum(fn (RentalItem $i) => (float) $i->deposit), 2),
            ])->save();

            return $rental->fresh(['items.asset', 'customer']);
        });
    }

    /**
     * Push the due time out on a live hire.
     *
     * The customer rings on Sunday and asks for another day. Its own operation
     * rather than an edit, because the hire is running: everything else on the
     * contract is now a fact, and the only thing that may still move is when it is
     * expected back.
     *
     * Refused backwards. Pulling the due time back to *now* on a hire that is
     * already three days over would erase the late fee, and a "correction" that
     * quietly writes off money is the one edit an audit will ask about.
     */
    public function extend(Rental $rental, string|Carbon $newDueAt): Rental
    {
        if ($rental->status !== 'out') {
            throw new RuntimeException("Only a hire that is out can be extended — {$rental->rental_number} is {$rental->status}.");
        }

        $due = $newDueAt instanceof Carbon ? $newDueAt : $this->at($newDueAt);

        if ($due === null || $due->lte($rental->due_at)) {
            throw new RuntimeException('An extension has to be later than the time already promised.');
        }

        $rental->loadMissing('items');
        $outstanding = $rental->outstandingItems()->pluck('asset_id')->all();

        // The kit may have been promised to somebody else for exactly the period
        // being asked for. Better to say so now than to double-book the weekend.
        $clash = array_intersect(
            $outstanding,
            $this->availability->occupiedAssetIds($rental->due_at, $due, $rental->id),
        );

        if ($clash !== []) {
            $codes = RentalAsset::query()->whereIn('id', $clash)->pluck('asset_code')->implode(', ');

            throw new RuntimeException("Cannot extend — {$codes} is promised to another customer in that period.");
        }

        $rental->forceFill(['due_at' => $due])->save();

        return $rental->fresh(['items.asset', 'customer']);
    }

    /**
     * Take kit back — some of it, or all of it.
     *
     * Each line is priced at the instant it returns and then frozen, so a bike
     * back on time keeps its clean bill however long its neighbour stays out.
     * Damage is charged per line, with the condition it went out in already on the
     * record to argue from.
     *
     * @param  list<array{item_id: int, condition_in?: string, damage_charge?: float, damage_note?: string|null, send_to_bench?: bool}>  $returns
     */
    public function returnItems(Rental $rental, array $returns, ?Carbon $at = null, ?int $userId = null): Rental
    {
        if (! in_array($rental->status, ['out', 'returned'], true)) {
            throw new RuntimeException("Nothing is out on {$rental->rental_number} — it is {$rental->status}.");
        }

        $at ??= now();
        $rental->loadMissing('items.asset');

        return DB::transaction(function () use ($rental, $returns, $at) {
            foreach ($returns as $row) {
                $item = $rental->items->firstWhere('id', $row['item_id']);

                if (! $item) {
                    throw new RuntimeException('That line is not on this hire.');
                }

                /**
                 * Skipped rather than refused. "Take everything back" is a button
                 * a counter will press twice, and the second press must not
                 * re-price a line at a later instant and add a day's late fee to
                 * kit that is standing in the shop.
                 */
                if ($item->isReturned()) {
                    continue;
                }

                $priced = $this->pricing->priceLine($rental, $item, $at);
                $conditionIn = $row['condition_in'] ?? $item->condition_out;
                $damage = round((float) ($row['damage_charge'] ?? 0), 2);

                $item->forceFill([
                    'returned_at' => $at,
                    'condition_in' => $conditionIn,
                    'hire_units' => $priced['hire_units'],
                    'hire_charge' => $priced['hire_charge'],
                    'late_units' => $priced['late_units'],
                    'late_charge' => $priced['late_charge'],
                    'damage_charge' => $damage,
                    'damage_note' => $row['damage_note'] ?? null,
                ])->save();

                $this->settleAsset($item, $conditionIn, (bool) ($row['send_to_bench'] ?? false));
            }

            $rental->load('items');
            $this->billing->reprice($rental);

            if ($rental->isFullyReturned()) {
                $rental->forceFill(['status' => 'returned', 'returned_at' => $at])->save();
            }

            return $rental->fresh(['items.asset', 'customer']);
        });
    }

    /**
     * Bill the hire and deal with the deposit. See RentalBillingService for why
     * the deposit never travels through the sales spine.
     */
    public function close(Rental $rental, ?float $applyDeposit = null, ?int $userId = null)
    {
        if ($rental->status === 'closed') {
            return $rental->invoice()->first();
        }

        if ($rental->status !== 'returned') {
            $outstanding = $rental->loadMissing('items')->outstandingItems()->count();

            throw new RuntimeException(
                $outstanding > 0
                    ? "{$outstanding} item(s) are still out on {$rental->rental_number}. Take them back before settling."
                    : "Hire {$rental->rental_number} is {$rental->status} and cannot be settled.",
            );
        }

        return $this->billing->settle($rental, $applyDeposit, $userId);
    }

    /**
     * Drop a booking that is not going to happen.
     *
     * Only a booking. A hire that is out is somewhere in the world with a customer,
     * and marking it cancelled would take the kit out of the overdue board — which
     * is the one place anybody would ever have noticed it was missing.
     */
    public function cancel(Rental $rental, ?string $reason = null): Rental
    {
        if ($rental->status !== 'booked') {
            throw new RuntimeException(
                $rental->status === 'out'
                    ? "The kit on {$rental->rental_number} is with the customer. Take it back — a hire that is out cannot be cancelled."
                    : "Hire {$rental->rental_number} is {$rental->status} and cannot be cancelled.",
            );
        }

        $rental->forceFill([
            'status' => 'cancelled',
            'notes' => trim((string) $rental->notes."\nCancelled: ".($reason ?? 'no reason given')),
        ])->save();

        return $rental;
    }

    /**
     * What the fleet does about a returned line.
     *
     * The condition on the way in is copied onto the asset, because that is now
     * the truth about it and the next customer's contract has to start from there.
     * Two of the six values change availability as well, and both must: kit on the
     * bench cannot be promised to anybody, and kit that never came back cannot be
     * promised to anybody ever again.
     */
    private function settleAsset(RentalItem $item, string $conditionIn, bool $sendToBench): void
    {
        $asset = $item->asset;

        if (! $asset) {
            return;
        }

        if ($conditionIn === 'lost') {
            $asset->forceFill(['status' => 'retired', 'condition' => 'damaged'])->save();

            return;
        }

        $asset->forceFill([
            'condition' => $conditionIn,
            /**
             * A damaged return does not go to the bench by itself. A scuffed bat
             * is 'damaged' and goes straight back on the rack; a bent wheel is
             * 'damaged' and does not. Only the person looking at it knows which,
             * so the flag is theirs — but the condition is recorded either way.
             */
            'status' => $sendToBench ? 'maintenance' : $asset->status,
        ])->save();
    }

    /**
     * Resolve and validate the asset rows on a contract.
     *
     * @param  list<array{asset_id: int, condition_out?: string}>  $rows
     * @return array<int, RentalAsset>
     */
    private function assetsFor(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $r) => (int) $r['asset_id'], $rows)));

        if ($ids === []) {
            throw new RuntimeException('A hire needs at least one piece of kit on it.');
        }

        if (count($ids) !== count($rows)) {
            throw new RuntimeException('The same item is on this hire twice — one line per piece of kit.');
        }

        $assets = RentalAsset::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if (! $assets->has($id)) {
                throw new RuntimeException('One of the items chosen is not in the hire fleet.');
            }
        }

        return $assets->all();
    }

    /**
     * @param  array<int, RentalAsset>  $assets
     */
    private function assertAvailable(array $assets, Carbon $from, Carbon $to, string $basis, ?int $ignoreRentalId = null): void
    {
        foreach ($assets as $asset) {
            $refusal = $this->availability->refusalFor($asset, $from, $to, $basis, $ignoreRentalId);

            if ($refusal) {
                throw new RuntimeException($refusal);
            }
        }
    }

    /**
     * @param  array<int, RentalAsset>  $assets
     * @param  list<array{asset_id: int, condition_out?: string}>  $rows
     */
    private function writeLines(Rental $rental, array $assets, array $rows): void
    {
        foreach ($rows as $row) {
            $asset = $assets[(int) $row['asset_id']];
            $rate = $asset->rateFor($rental->rate_basis);

            if ($rate === null) {
                throw new RuntimeException(
                    "{$asset->label()} has no {$rental->rate_basis} rate — it is not offered on that basis.",
                );
            }

            $rental->items()->create([
                'asset_id' => $asset->id,
                // Snapshots. The rate card behind them moves with the season and
                // the contract must not — see RentalItem.
                'rate' => $rate,
                'deposit' => $asset->deposit_amount,
                'late_fee_percent' => $asset->late_fee_percent,
                'condition_out' => $row['condition_out'] ?? $asset->condition,
            ]);
        }

        $rental->load('items');

        // Held on the header from the moment of booking so the counter can quote
        // "and ₹4,000 deposit" before anything is handed over; re-summed at issue
        // against what actually left.
        $rental->forceFill([
            'deposit_amount' => round($rental->items->sum(fn (RentalItem $i) => (float) $i->deposit), 2),
        ])->save();
    }

    private function at(?string $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value);
    }
}
