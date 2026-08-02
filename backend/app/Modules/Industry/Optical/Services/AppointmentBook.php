<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Services;

use App\Models\EyePrescription;
use App\Modules\Industry\Optical\Models\OpticalAppointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The rules of the diary.
 *
 * An appointment book has exactly one rule worth enforcing in software: the same
 * person cannot be in two chairs at once. Everything else a shop does with its
 * diary — reshuffling, running late, squeezing someone in — is judgement, and
 * software that refuses it is software the shop works around.
 *
 * So the clash check is narrow on purpose:
 *
 *  - It only defends *clinical* slots. A collection or an adjustment happens at
 *    the counter while a refraction is going on behind the curtain, and refusing
 *    those as clashes would make the book unusable on a Saturday.
 *  - It only defends a *named* optometrist against themselves. A shop that does
 *    not say who is doing the test has not told the book enough to protect, and
 *    guessing "the shop can only do one at a time" is wrong for every practice
 *    with two chairs.
 *  - Branches are independent: a slot at another warehouse is not a clash.
 */
class AppointmentBook
{
    /**
     * Slots that would overlap this one.
     *
     * @param  array<string, mixed>  $data  scheduled_at, duration_minutes, purpose, optometrist_id, warehouse_id
     * @return Collection<int, OpticalAppointment>
     */
    public function clashesFor(array $data, ?int $ignoreId = null): Collection
    {
        $purpose = $data['purpose'] ?? 'eye_test';
        $optometristId = $data['optometrist_id'] ?? null;

        // Nothing to defend: a counter job, or a slot with nobody named on it.
        if (! in_array($purpose, OpticalAppointment::CLINICAL_PURPOSES, true) || ! $optometristId) {
            return collect();
        }

        $start = Carbon::parse($data['scheduled_at']);
        $end = $start->copy()->addMinutes(max(1, (int) ($data['duration_minutes'] ?? 20)));

        return OpticalAppointment::query()
            ->open()
            ->where('optometrist_id', $optometristId)
            ->whereIn('purpose', OpticalAppointment::CLINICAL_PURPOSES)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->when(
                array_key_exists('warehouse_id', $data) && $data['warehouse_id'],
                fn ($q) => $q->where('warehouse_id', $data['warehouse_id']),
            )
            /**
             * The window is widened rather than joined against a computed end
             * time: durations live on the row and no portable SQL adds a column
             * of minutes to a timestamp across three drivers. A day's diary for
             * one person is a handful of rows, so the overlap is settled in PHP.
             */
            ->whereBetween('scheduled_at', [$start->copy()->subHours(4), $end->copy()->addHours(4)])
            ->get()
            ->filter(function (OpticalAppointment $slot) use ($start, $end) {
                $slotEnd = $slot->endsAt();

                return $slotEnd !== null && $slot->scheduled_at < $end && $slotEnd > $start;
            })
            ->values();
    }

    /**
     * Mark somebody in. Stamps the wait clock the shop is judged on.
     */
    public function arrive(OpticalAppointment $slot): OpticalAppointment
    {
        $slot->forceFill([
            'status' => 'arrived',
            'arrived_at' => $slot->arrived_at ?? now(),
        ])->save();

        return $slot;
    }

    /**
     * Close a slot off, optionally against the test that came out of it.
     *
     * The prescription link is why completing is an act rather than a status
     * field: a finished eye-test slot with no test behind it is the thing a
     * practice cannot audit afterwards, and this is where the two records are
     * tied together.
     */
    public function complete(OpticalAppointment $slot, ?EyePrescription $prescription = null): OpticalAppointment
    {
        $slot->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'eye_prescription_id' => $prescription?->id ?? $slot->eye_prescription_id,
        ])->save();

        return $slot;
    }

    /**
     * What the day looks like.
     *
     * @return array<string, int>
     */
    public function daySummary(Carbon|string $day): array
    {
        $slots = OpticalAppointment::query()->onDay($day)->get();

        return [
            'total' => $slots->count(),
            'waiting' => $slots->where('status', 'arrived')->count(),
            'upcoming' => $slots->whereIn('status', ['booked', 'confirmed'])
                ->filter(fn (OpticalAppointment $s) => ! $s->isOverdue())->count(),
            'overdue' => $slots->filter(fn (OpticalAppointment $s) => $s->isOverdue())->count(),
            'completed' => $slots->where('status', 'completed')->count(),
            'no_show' => $slots->where('status', 'no_show')->count(),
        ];
    }
}
