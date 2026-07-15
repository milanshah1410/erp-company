<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HsnCode;
use App\Support\GstinValidator;
use Illuminate\Http\Request;

/**
 * Lightweight GST helper endpoints (no persisted state of their own):
 *  - validateGstin → structural + check-digit validation with decoded state/PAN.
 *  - hsnLookup     → type-ahead against the tenant's HSN master.
 */
class GstController extends Controller
{
    public function validateGstin(Request $request)
    {
        $validated = $request->validate([
            'gstin' => ['required', 'string', 'max:20'],
        ]);

        return response()->json(GstinValidator::validate($validated['gstin']));
    }

    public function hsnLookup(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        $matches = HsnCode::query()
            ->where('is_active', true)
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('code', 'like', "{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            }))
            ->orderBy('code')
            ->limit(20)
            ->get(['id', 'code', 'description', 'gst_rate', 'cess_rate', 'uqc', 'type']);

        return response()->json(['data' => $matches]);
    }
}
