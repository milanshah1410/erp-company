<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EyePrescription;
use App\Modules\Industry\Optical\Http\Requests\OpticalAppointmentRequest;
use App\Modules\Industry\Optical\Models\OpticalAppointment;
use App\Modules\Industry\Optical\Services\AppointmentBook;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The appointment book.
 *
 * The default listing is today, in time order, because that is what a shop opens
 * this screen to see. History is behind an explicit date — a diary that opens on
 * last month's no-shows is a diary nobody uses.
 */
class OpticalAppointmentController extends Controller
{
    public function __construct(private readonly AppointmentBook $book) {}

    public function index(Request $request)
    {
        $query = OpticalAppointment::query()->with([
            'customer:id,name,phone',
            'optometrist:id,employee_code,first_name,last_name',
            'prescription:id,prescription_number',
            'order:id,order_number,status',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('appointment_number', 'like', "%{$search}%")
                    ->orWhere('patient_name', 'like', "%{$search}%")
                    ->orWhere('patient_phone', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'purpose', 'optometrist_id', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // One day, or a range for the week view. `open_only` overrides both: a
        // shop chasing unconfirmed slots does not care which day they fall on.
        if ($request->boolean('open_only')) {
            $query->open()->orderBy('scheduled_at');
        } elseif ($request->filled('from') || $request->filled('to')) {
            $from = Carbon::parse($request->query('from', 'today'))->startOfDay();
            $to = Carbon::parse($request->query('to', $from->toDateString()))->endOfDay();
            $query->whereBetween('scheduled_at', [$from, $to])->orderBy('scheduled_at');
        } else {
            $query->onDay($request->query('day', now()->toDateString()));
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        return response()->json(
            $query->paginate($perPage)->through(fn (OpticalAppointment $a) => $this->row($a))->toArray()
            + ['summary' => $this->book->daySummary($request->query('day', now()->toDateString()))],
        );
    }

    public function store(OpticalAppointmentRequest $request)
    {
        $data = $request->validated();
        $this->assertFree($data);

        $slot = OpticalAppointment::create($data + [
            'appointment_number' => OpticalAppointment::nextNumber(),
            'status' => 'booked',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($slot->load($this->relations()))], 201);
    }

    public function show(OpticalAppointment $appointment)
    {
        return response()->json(['data' => $this->row($appointment->load($this->relations()))]);
    }

    public function update(OpticalAppointmentRequest $request, OpticalAppointment $appointment)
    {
        if ($appointment->isClosed()) {
            return response()->json([
                'message' => 'This slot is already closed. Book a new one rather than rewriting what happened.',
            ], 422);
        }

        $data = $request->validated();
        $this->assertFree($data, $appointment->id);
        $appointment->update($data);

        return response()->json(['data' => $this->row($appointment->load($this->relations()))]);
    }

    public function destroy(OpticalAppointment $appointment)
    {
        /**
         * A slot somebody was seen in is a record of work done, not a mistake to
         * be erased — cancel it instead, which leaves the fact that it existed.
         * Only a slot nothing came of can go.
         */
        if (in_array($appointment->status, ['arrived', 'completed'], true)) {
            return response()->json([
                'message' => 'This patient was seen. Cancel the slot instead of deleting it.',
            ], 422);
        }

        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted.']);
    }

    /**
     * Move the slot along: confirmed → arrived → completed, or off the board as
     * a no-show or a cancellation.
     *
     * Completing may name the eye test that came out of it, which is the whole
     * reason this is an endpoint rather than a writable status field — a
     * finished test slot with nothing behind it is what a practice cannot audit
     * afterwards.
     */
    public function setStatus(Request $request, OpticalAppointment $appointment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'arrived', 'completed', 'no_show', 'cancelled'])],
            'eye_prescription_id' => ['nullable', 'exists:eye_prescriptions,id'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! $appointment->canMoveTo($data['status'])) {
            return response()->json([
                'message' => "A slot at '{$appointment->status}' cannot move to '{$data['status']}'.",
            ], 422);
        }

        if (! empty($data['notes'])) {
            $appointment->forceFill(['notes' => $data['notes']])->save();
        }

        match ($data['status']) {
            'arrived' => $this->book->arrive($appointment),
            'completed' => $this->book->complete(
                $appointment,
                isset($data['eye_prescription_id']) ? EyePrescription::find($data['eye_prescription_id']) : null,
            ),
            default => $appointment->forceFill(['status' => $data['status']])->save(),
        };

        return response()->json([
            'data' => $this->row($appointment->refresh()->load($this->relations())),
            'message' => "Appointment {$appointment->appointment_number} marked {$data['status']}.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertFree(array $data, ?int $ignoreId = null): void
    {
        $clashes = $this->book->clashesFor($data, $ignoreId);

        if ($clashes->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'scheduled_at' => $clashes->map(fn (OpticalAppointment $c) => sprintf(
                '%s is already with %s from %s (%s).',
                $c->optometrist?->fullName() ?: 'That optometrist',
                $c->patient_name,
                $c->scheduled_at?->format('H:i'),
                $c->appointment_number,
            ))->all(),
        ]);
    }

    private function row(OpticalAppointment $a): array
    {
        return [
            'id' => $a->id,
            'appointment_number' => $a->appointment_number,
            'customer_id' => $a->customer_id,
            'customer' => $a->relationLoaded('customer') && $a->customer
                ? ['id' => $a->customer->id, 'name' => $a->customer->name, 'phone' => $a->customer->phone]
                : null,
            'patient_name' => $a->patient_name,
            'patient_phone' => $a->patient_phone,
            'patient_age' => $a->patient_age,

            'purpose' => $a->purpose,
            'status' => $a->status,
            'is_clinical' => $a->isClinical(),
            'is_overdue' => $a->isOverdue(),

            'scheduled_at' => $a->scheduled_at?->toIso8601String(),
            'ends_at' => $a->endsAt()?->toIso8601String(),
            'duration_minutes' => (int) $a->duration_minutes,

            'optometrist_id' => $a->optometrist_id,
            // `employees` holds first and last names, not one — fullName() is
            // where that is assembled for every screen that shows staff.
            'optometrist' => $a->relationLoaded('optometrist') ? $a->optometrist?->fullName() : null,
            'warehouse_id' => $a->warehouse_id,

            'eye_prescription_id' => $a->eye_prescription_id,
            'prescription_number' => $a->relationLoaded('prescription') ? $a->prescription?->prescription_number : null,
            'optical_order_id' => $a->optical_order_id,
            'order_number' => $a->relationLoaded('order') ? $a->order?->order_number : null,

            'arrived_at' => $a->arrived_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'reminder_sent_at' => $a->reminder_sent_at?->toIso8601String(),
            'notes' => $a->notes,
        ];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'customer:id,name,phone', 'optometrist:id,employee_code,first_name,last_name',
            'prescription:id,prescription_number', 'order:id,order_number,status',
        ];
    }
}
