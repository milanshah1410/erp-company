<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\Agriculture\Http\Requests\AgriLicenceRequest;
use App\Modules\Industry\Agriculture\Models\AgriLicence;
use Illuminate\Http\Request;

/**
 * The licence register: what this shop is currently allowed to sell.
 *
 * Read as a compliance screen rather than a list of documents. The summary answers
 * the only question that matters — is there a valid licence behind each kind of
 * controlled stock actually on the shelves? — because the failure mode is not a
 * missing record, it is a lapsed one nobody looked at while the season was on.
 */
class AgriLicenceController extends Controller
{
    /** Product input types that each licence type authorises the sale of. */
    private const COVERS = [
        'seed' => ['seed'],
        'fertilizer' => ['fertilizer'],
        'insecticide' => ['pesticide'],
    ];

    public function index(Request $request)
    {
        $query = AgriLicence::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('licence_number', 'like', "%{$search}%")
                    ->orWhere('holder_name', 'like', "%{$search}%")
                    ->orWhere('authority', 'like', "%{$search}%");
            });
        }
        if ($type = $request->query('licence_type')) {
            $query->where('licence_type', $type);
        }

        // Ordered by what expires next, not by when it was keyed: the register is
        // consulted to find out what needs renewing.
        $rows = $query->orderByRaw('valid_till is null, valid_till asc')->get();

        // Effective status is derived per row (see AgriLicence::effectiveStatus),
        // so filtering on it happens here rather than in SQL. The register is a
        // handful of rows per shop — a licence per input type, plus history.
        if ($status = $request->query('status')) {
            $rows = $rows->filter(fn (AgriLicence $l) => $l->effectiveStatus() === $status)->values();
        }

        return response()->json([
            'data' => $rows->map(fn (AgriLicence $l) => $this->row($l))->values(),
            'summary' => $this->summary(),
        ]);
    }

    public function show(AgriLicence $agriLicence)
    {
        return response()->json(['data' => $this->row($agriLicence)]);
    }

    public function store(AgriLicenceRequest $request)
    {
        $licence = AgriLicence::create($request->validated() + [
            'status' => $request->input('status', 'active'),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($licence)], 201);
    }

    public function update(AgriLicenceRequest $request, AgriLicence $agriLicence)
    {
        $agriLicence->update($request->validated());

        return response()->json(['data' => $this->row($agriLicence->fresh())]);
    }

    public function destroy(AgriLicence $agriLicence)
    {
        $agriLicence->delete();

        return response()->json(['message' => 'Licence deleted.']);
    }

    /**
     * Renewal watchlist plus a coverage check.
     *
     * `coverage` pairs each controlled input type the shop actually stocks with
     * whether a valid licence stands behind it. Stock without cover is the
     * finding worth surfacing: a lapsed pesticide licence matters precisely
     * because there are forty litres of pesticide in the back room.
     */
    private function summary(): array
    {
        $licences = AgriLicence::all();

        $stocked = Product::query()
            ->whereNotNull('agri_input_type')
            ->where('is_active', true)
            ->selectRaw('agri_input_type, count(*) as products')
            ->groupBy('agri_input_type')
            ->pluck('products', 'agri_input_type');

        $coverage = [];
        foreach (self::COVERS as $licenceType => $inputTypes) {
            $products = 0;
            foreach ($inputTypes as $inputType) {
                $products += (int) ($stocked[$inputType] ?? 0);
            }

            $valid = $licences
                ->where('licence_type', $licenceType)
                ->first(fn (AgriLicence $l) => $l->isValidToday());

            $coverage[] = [
                'licence_type' => $licenceType,
                'products' => $products,
                'covered' => $valid !== null,
                'valid_till' => $valid?->valid_till?->toDateString(),
                // Stock on the shelves with no licence behind it.
                'at_risk' => $products > 0 && $valid === null,
            ];
        }

        return [
            'total' => $licences->count(),
            'expiring' => $licences->filter(fn (AgriLicence $l) => $l->effectiveStatus() === 'expiring')->count(),
            'expired' => $licences->filter(fn (AgriLicence $l) => $l->effectiveStatus() === 'expired')->count(),
            'warning_days' => AgriLicence::EXPIRY_WARNING_DAYS,
            'coverage' => $coverage,
        ];
    }

    private function row(AgriLicence $l): array
    {
        return [
            'id' => $l->id,
            'licence_type' => $l->licence_type,
            'licence_number' => $l->licence_number,
            'form_number' => $l->form_number,
            'authority' => $l->authority,
            'holder_name' => $l->holder_name,
            'premises' => $l->premises,
            'issued_on' => $l->issued_on?->toDateString(),
            'valid_till' => $l->valid_till?->toDateString(),
            'status' => $l->status,
            // What the screen colours on: 'active' | 'expiring' | 'expired' |
            // 'suspended' | 'surrendered'.
            'effective_status' => $l->effectiveStatus(),
            'days_to_expiry' => $l->daysToExpiry(),
            'remarks' => $l->remarks,
            'created_at' => $l->created_at,
        ];
    }
}
