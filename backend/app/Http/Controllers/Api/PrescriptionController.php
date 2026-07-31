<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrescriptionRequest;
use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use App\Models\PrescriptionDispense;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Prescriptions and the Schedule H1 / X register.
 *
 * The register is the reason this module exists: a drug inspector asks for
 * every H1 and X sale over a date range, with patient and prescriber, and the
 * shop has to produce it. {@see register()} is that document.
 */
class PrescriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = Prescription::query()
            ->with('customer:id,name')
            ->withCount('dispenses');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('prescription_number', 'like', "%{$search}%")
                    ->orWhere('patient_name', 'like', "%{$search}%")
                    ->orWhere('patient_phone', 'like', "%{$search}%")
                    ->orWhere('doctor_name', 'like', "%{$search}%");
            });
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('from')) {
            $query->where('prescribed_on', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('prescribed_on', '<=', $request->query('to'));
        }
        // The counter's picker: only slips it can actually dispense against.
        if ($request->boolean('dispensable_only')) {
            $query->where(function ($q) {
                $q->where('valid_until', '>=', now()->toDateString())
                    ->orWhere(fn ($r) => $r->whereNull('valid_until')->whereDoesntHave('dispenses'));
            });
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return PrescriptionResource::collection($query->paginate($perPage));
    }

    public function store(PrescriptionRequest $request)
    {
        $prescription = Prescription::create($request->validated() + [
            'prescription_number' => Prescription::nextNumber(),
            'created_by' => $request->user()?->id,
        ]);

        return (new PrescriptionResource($prescription->load('customer:id,name')))
            ->response()->setStatusCode(201);
    }

    public function show(Prescription $prescription)
    {
        return new PrescriptionResource(
            $prescription->load(['customer:id,name', 'dispenses.invoice:id,invoice_number']),
        );
    }

    public function update(PrescriptionRequest $request, Prescription $prescription)
    {
        // Once medicine has gone out against a slip, the slip is evidence: the
        // register entry and the prescription behind it have to keep saying the
        // same thing.
        if ($prescription->dispenses()->exists()) {
            return response()->json([
                'message' => 'This prescription has already been dispensed against and can no longer be edited.',
            ], 422);
        }

        $prescription->update($request->validated());

        return new PrescriptionResource($prescription->load('customer:id,name'));
    }

    public function destroy(Prescription $prescription)
    {
        if ($prescription->dispenses()->exists()) {
            return response()->json([
                'message' => 'This prescription backs a register entry and cannot be deleted.',
            ], 422);
        }

        $prescription->delete();

        return response()->json(['message' => 'Prescription deleted.']);
    }

    /**
     * The Schedule H1 / X register for a date range.
     *
     * Rendered from the dispensing log rather than from invoices, so it shows
     * what was actually handed over and survives a later product rename or
     * reclassification.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'schedule' => ['nullable', Rule::in(PrescriptionDispense::REGISTERED_SCHEDULES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        // Default to the current month — the range a counter is usually asked
        // for, and small enough not to page through a year by accident.
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $query = PrescriptionDispense::query()
            ->with(['prescription:id,prescription_number,patient_name,patient_age,patient_gender,doctor_name,doctor_registration_no', 'invoice:id,invoice_number'])
            ->whereIn('drug_schedule', $data['schedule'] ?? PrescriptionDispense::REGISTERED_SCHEDULES)
            ->whereBetween('dispensed_on', [$from, $to])
            ->orderBy('dispensed_on')
            ->orderBy('id');

        $page = $query->paginate((int) ($data['per_page'] ?? 100));

        $page->getCollection()->transform(fn (PrescriptionDispense $d) => [
            'id' => $d->id,
            'dispensed_on' => $d->dispensed_on?->toDateString(),
            'drug_schedule' => $d->drug_schedule,
            'product_name' => $d->product_name,
            'quantity' => (float) $d->quantity,
            'invoice_number' => $d->invoice?->invoice_number,
            'prescription_number' => $d->prescription?->prescription_number,
            'patient_name' => $d->prescription?->patient_name,
            'patient_age' => $d->prescription?->patient_age,
            'patient_gender' => $d->prescription?->patient_gender,
            'doctor_name' => $d->prescription?->doctor_name,
            'doctor_registration_no' => $d->prescription?->doctor_registration_no,
        ]);

        return response()->json($page->toArray() + [
            'range' => ['from' => $from, 'to' => $to],
            'summary' => $this->registerSummary($from, $to),
        ]);
    }

    /** Entry counts per schedule for the range, for the register's header. */
    private function registerSummary(string $from, string $to): array
    {
        $rows = PrescriptionDispense::query()
            ->whereIn('drug_schedule', PrescriptionDispense::REGISTERED_SCHEDULES)
            ->whereBetween('dispensed_on', [$from, $to])
            ->selectRaw('drug_schedule, COUNT(*) as entries, COUNT(DISTINCT prescription_id) as prescriptions')
            ->groupBy('drug_schedule')
            ->get();

        return [
            'entries' => (int) $rows->sum('entries'),
            'prescriptions' => (int) $rows->sum('prescriptions'),
            'by_schedule' => $rows->pluck('entries', 'drug_schedule'),
        ];
    }
}
