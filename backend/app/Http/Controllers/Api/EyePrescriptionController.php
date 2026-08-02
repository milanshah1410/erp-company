<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EyePrescriptionRequest;
use App\Models\EyePrescription;
use App\Services\OpticalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Eye tests. The powers here are what a pair of spectacles gets ground to, so
 * they are validated as dispensable — not merely well-formed — before they can
 * be saved.
 */
class EyePrescriptionController extends Controller
{
    public function __construct(private readonly OpticalService $optical) {}

    public function index(Request $request)
    {
        $query = EyePrescription::query()
            ->with('customer:id,name')
            ->withCount('orders');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('prescription_number', 'like', "%{$search}%")
                    ->orWhere('patient_name', 'like', "%{$search}%")
                    ->orWhere('patient_phone', 'like', "%{$search}%");
            });
        }
        foreach (['customer_id', 'vision_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        // The order form's picker: tests that have not gone stale.
        if ($request->boolean('valid_only')) {
            $query->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return response()->json(
            $query->paginate($perPage)->through(fn (EyePrescription $p) => $this->row($p))->toArray(),
        );
    }

    public function store(EyePrescriptionRequest $request)
    {
        $data = $request->validated();
        $this->assertDispensable($data);

        $prescription = EyePrescription::create($data + [
            'prescription_number' => EyePrescription::nextNumber(),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($prescription->load('customer:id,name'))], 201);
    }

    public function show(EyePrescription $eyePrescription)
    {
        return response()->json([
            'data' => $this->row($eyePrescription->load('customer:id,name')->loadCount('orders')),
        ]);
    }

    public function update(EyePrescriptionRequest $request, EyePrescription $eyePrescription)
    {
        // Once spectacles have been made to a test, the test is the record of
        // what they were made to. A re-test is a new prescription, not an edit
        // of the old one — that is also how an optician's file works.
        if ($eyePrescription->orders()->exists()) {
            return response()->json([
                'message' => 'Spectacles have already been ordered against this test. Record a new prescription instead.',
            ], 422);
        }

        $data = $request->validated();
        $this->assertDispensable($data);
        $eyePrescription->update($data);

        return response()->json(['data' => $this->row($eyePrescription->load('customer:id,name'))]);
    }

    public function destroy(EyePrescription $eyePrescription)
    {
        if ($eyePrescription->orders()->exists()) {
            return response()->json([
                'message' => 'Spectacles have been ordered against this test and it cannot be deleted.',
            ], 422);
        }

        $eyePrescription->delete();

        return response()->json(['message' => 'Prescription deleted.']);
    }

    /** @param  array<string, mixed>  $data */
    private function assertDispensable(array $data): void
    {
        $errors = $this->optical->validatePrescription($data);

        if ($errors !== []) {
            throw ValidationException::withMessages(['powers' => $errors]);
        }
    }

    private function row(EyePrescription $p): array
    {
        return [
            'id' => $p->id,
            'prescription_number' => $p->prescription_number,
            'customer_id' => $p->customer_id,
            'customer' => $p->relationLoaded('customer') && $p->customer
                ? ['id' => $p->customer->id, 'name' => $p->customer->name]
                : null,
            'patient_name' => $p->patient_name,
            'patient_age' => $p->patient_age,
            'patient_gender' => $p->patient_gender,
            'patient_phone' => $p->patient_phone,
            'optometrist_name' => $p->optometrist_name,
            'optometrist_registration_no' => $p->optometrist_registration_no,
            'vision_type' => $p->vision_type,
            'has_add' => $p->hasAdd(),

            'right' => [
                'sph' => $p->right_sph !== null ? (float) $p->right_sph : null,
                'cyl' => $p->right_cyl !== null ? (float) $p->right_cyl : null,
                'axis' => $p->right_axis,
                'add' => $p->right_add !== null ? (float) $p->right_add : null,
                'pd' => $p->right_pd !== null ? (float) $p->right_pd : null,
            ],
            'left' => [
                'sph' => $p->left_sph !== null ? (float) $p->left_sph : null,
                'cyl' => $p->left_cyl !== null ? (float) $p->left_cyl : null,
                'axis' => $p->left_axis,
                'add' => $p->left_add !== null ? (float) $p->left_add : null,
                'pd' => $p->left_pd !== null ? (float) $p->left_pd : null,
            ],
            // How an optician reads it — one line per eye beats eight fields.
            'right_formatted' => $p->formatted('right'),
            'left_formatted' => $p->formatted('left'),
            'pd' => $p->pd !== null ? (float) $p->pd : null,

            'prescribed_on' => $p->prescribed_on?->toDateString(),
            'valid_until' => $p->valid_until?->toDateString(),
            /**
             * Whether the paper the customer brought in is on file. The scan
             * itself is served by the Optical & Eyewear plugin behind a signed
             * URL — this is only the flag the list needs to show a paperclip,
             * and it reads a column of this table rather than the plugin.
             */
            'has_attachment' => $p->hasAttachment(),
            'attachment_name' => $p->attachment_name,
            'is_expired' => $p->isExpired(),
            'orders_count' => $p->orders_count ?? null,
            'notes' => $p->notes,
        ];
    }
}
