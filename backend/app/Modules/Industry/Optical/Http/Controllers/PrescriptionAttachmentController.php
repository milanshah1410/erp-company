<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EyePrescription;
use App\Support\TenantStorage;
use Illuminate\Http\Request;

/**
 * The prescription the customer walked in with.
 *
 * The powers stay keyed — the lab is sent numbers, and a photograph cannot be
 * checked in quarter dioptres — so this is not an alternative way to record a
 * test. It is the evidence beside it: when a customer says the outside
 * ophthalmologist wrote something other than what was ground, the scan is what
 * settles it, and a typed copy is only the shop's word.
 *
 * One file per test, replaced rather than accumulated. A prescription is one
 * piece of paper; keeping every superseded photograph of it would leave a
 * private bucket nobody ever cleans.
 *
 * Patient material, so: stored through TenantStorage on the tenant's private
 * folder, and handed back only as a short-lived signed URL. There is no public
 * path to it at any point.
 */
class PrescriptionAttachmentController extends Controller
{
    public function store(Request $request, EyePrescription $eyePrescription)
    {
        $request->validate([
            // Photographed on a phone at the counter or scanned to PDF — those
            // are the two things that actually arrive.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $disk = TenantStorage::disk();
        $file = $request->file('file');
        $path = $disk->putFile('eye-prescriptions', $file);

        if ($eyePrescription->attachment_path) {
            $disk->delete($eyePrescription->attachment_path);
        }

        $eyePrescription->forceFill([
            'attachment_path' => $path,
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_uploaded_at' => now(),
        ])->save();

        return response()->json(['data' => $this->payload($eyePrescription)], 201);
    }

    /** The signed link. Regenerated per request, because a stored one would outlive its signature. */
    public function show(EyePrescription $eyePrescription)
    {
        if (! $eyePrescription->attachment_path) {
            return response()->json(['message' => 'No prescription has been attached to this test.'], 404);
        }

        return response()->json(['data' => $this->payload($eyePrescription)]);
    }

    public function destroy(EyePrescription $eyePrescription)
    {
        if ($eyePrescription->attachment_path) {
            TenantStorage::disk()->delete($eyePrescription->attachment_path);
        }

        $eyePrescription->forceFill([
            'attachment_path' => null,
            'attachment_name' => null,
            'attachment_uploaded_at' => null,
        ])->save();

        return response()->json(['message' => 'Attachment removed.']);
    }

    /** @return array<string, mixed> */
    private function payload(EyePrescription $rx): array
    {
        return [
            'id' => $rx->id,
            'attachment_name' => $rx->attachment_name,
            'attachment_uploaded_at' => $rx->attachment_uploaded_at?->toIso8601String(),
            'attachment_url' => $rx->attachment_path ? TenantStorage::temporaryUrl($rx->attachment_path) : null,
        ];
    }
}
