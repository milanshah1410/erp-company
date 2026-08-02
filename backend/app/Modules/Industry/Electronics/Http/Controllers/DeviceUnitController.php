<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Modules\Industry\Electronics\Http\Requests\DeviceUnitRequest;
use App\Modules\Industry\Electronics\Models\DeviceUnit;
use Illuminate\Http\Request;

/**
 * The unit register.
 *
 * Two work lists come out of it and both are read every morning: what has been
 * sold and still needs installing, and what is on the shelf. `lookup` is a
 * first-class endpoint because the counter's actual question is neither of those
 * — it is a customer reading a number off the back of a machine, and the answer
 * has to arrive in one call with the cover and the history attached.
 */
class DeviceUnitController extends Controller
{
    public function index(Request $request)
    {
        $query = DeviceUnit::query()->with([
            'customer:id,name,phone',
            'product:id,sku,name',
        ]);

        if ($search = $request->query('search')) {
            // Serials and IMEIs get read out over the phone and typed back with
            // spaces and dashes in them; stripping punctuation is the difference
            // between finding the machine and telling a customer it was never
            // sold here.
            $bare = preg_replace('/[^A-Za-z0-9]/', '', (string) $search);

            $query->where(function ($q) use ($search, $bare) {
                $q->where('serial_no', 'like', "%{$bare}%")
                    ->orWhere('imei_1', 'like', "%{$bare}%")
                    ->orWhere('imei_2', 'like', "%{$bare}%")
                    ->orWhere('model_name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'customer_id', 'product_id', 'brand'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // The fitters' list: sold, needs installing, nobody has been yet. The
        // same predicate as DeviceUnit::awaitsInstallation(), expressed in SQL
        // because the work list has to paginate.
        if ($request->boolean('awaiting_installation')) {
            $query->where('status', 'sold')
                ->where('installation_required', true)
                ->whereNull('installed_at');
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (DeviceUnit $u) => $this->row($u))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(DeviceUnit $unit)
    {
        $unit->load([
            'customer:id,name,phone,email',
            'product:id,sku,name',
            'saleInvoice:id,invoice_number,invoice_date',
            'amcContract:id,contract_number,title,start_date,end_date,status',
        ]);

        return response()->json(['data' => $this->row($unit, detailed: true)]);
    }

    /**
     * "What is this?" — a serial or IMEI in, everything the counter needs out.
     *
     * One endpoint rather than three calls, because this is asked with a customer
     * standing there: the unit, whether anything covers it today and what that
     * cover pays for, and what has been done to it before. An unknown number
     * returns 200 with `found: false`, not a 404 — "we have no record of this
     * machine" is a legitimate answer to give across a counter, not an error.
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
        ]);

        $bare = preg_replace('/[^A-Za-z0-9]/', '', $data['identifier']);

        $unit = DeviceUnit::query()
            ->where(fn ($q) => $q->where('serial_no', $bare)
                ->orWhere('imei_1', $bare)
                ->orWhere('imei_2', $bare))
            ->with(['customer:id,name,phone', 'product:id,sku,name'])
            ->first();

        if (! $unit) {
            return response()->json([
                'found' => false,
                'data' => null,
                'warranties' => [],
                'history' => [],
            ]);
        }

        /**
         * Cover comes from the core warranty register, not from a register of
         * this plugin's own — the counter must never be given a second opinion
         * about who pays. The plugin only supplied the `unit_id` link that makes
         * this query possible, and the scope columns the counter has to read out.
         */
        $warranties = ProductWarranty::where('unit_id', $unit->id)
            ->orderByDesc('end_date')
            ->get()
            ->map(fn (ProductWarranty $w) => [
                'id' => $w->id,
                'warranty_number' => $w->warranty_number,
                'source' => $w->source,
                'end_date' => $w->end_date?->toDateString(),
                'is_live' => $w->covers(),
                // The reason it does not apply, so the counter can say "it ran
                // out in March" rather than "no cover found".
                'reason' => $w->refusalReason(),
                'coverage' => $w->coverageLabel(),
                'covers_parts' => (bool) $w->covers_parts,
                'covers_labour' => (bool) $w->covers_labour,
                'is_onsite' => (bool) $w->is_onsite,
                'days_remaining' => $w->daysRemaining(),
            ])->values();

        return response()->json([
            'found' => true,
            'data' => $this->row($unit, detailed: true),
            'warranties' => $warranties,
            'is_covered' => $warranties->contains('is_live', true),
            'history' => $this->history($unit),
        ]);
    }

    /**
     * Every job card ever raised on this unit.
     *
     * On the unit's own view permission rather than service-jobs.view: reading
     * out what was done last time is the counter's job, and whoever may look a
     * machine up has to be able to say it.
     */
    public function history(DeviceUnit $unit)
    {
        return ServiceJob::where('device_unit_id', $unit->id)
            ->orderByDesc('received_at')
            ->limit(50)
            ->get(['id', 'job_number', 'status', 'complaint', 'resolution', 'is_warranty', 'received_at', 'completed_at', 'total'])
            ->map(fn (ServiceJob $job) => [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'status' => $job->status,
                'complaint' => $job->complaint,
                'resolution' => $job->resolution,
                'is_warranty' => (bool) $job->is_warranty,
                'received_at' => $job->received_at?->toIso8601String(),
                'completed_at' => $job->completed_at?->toIso8601String(),
                'total' => (float) $job->total,
            ])->values();
    }

    public function store(DeviceUnitRequest $request)
    {
        $data = $request->validated();

        // Brand and model default off the product so a counter scanning stock in
        // does not have to retype what the catalogue already knows. Kept as
        // columns rather than read through the relation because the register also
        // holds machines this shop never sold, which have no product row.
        if (! empty($data['product_id'])) {
            $product = Product::find($data['product_id']);
            $data['model_name'] ??= $product?->name;
        }

        $unit = DeviceUnit::create($data + ['created_by' => $request->user()?->id]);

        return response()->json(['data' => $this->row($unit->fresh(['customer', 'product']), detailed: true)], 201);
    }

    public function update(DeviceUnitRequest $request, DeviceUnit $unit)
    {
        $unit->update($request->validated());

        return response()->json(['data' => $this->row($unit->fresh(['customer', 'product']), detailed: true)]);
    }

    /**
     * Mark a machine commissioned.
     *
     * Its own endpoint rather than a field on the edit form: this is what a
     * fitter does from a phone on someone's kitchen floor, and it should not
     * require opening a record with twenty other fields on it.
     */
    public function install(Request $request, DeviceUnit $unit)
    {
        $data = $request->validate(['installed_at' => ['nullable', 'date']]);

        if (! $unit->markInstalled($data['installed_at'] ?? null)) {
            return response()->json([
                'message' => "This unit was already recorded as installed on {$unit->installed_at?->format('d M Y')}.",
            ], 422);
        }

        return response()->json(['data' => $this->row($unit->fresh(['customer', 'product']), detailed: true)]);
    }

    public function destroy(DeviceUnit $unit)
    {
        if (ServiceJob::where('device_unit_id', $unit->id)->exists()) {
            return response()->json([
                'message' => 'Work has been done on this unit. Mark it scrapped or inactive instead of deleting it.',
            ], 422);
        }

        if (ProductWarranty::where('unit_id', $unit->id)->exists()) {
            return response()->json([
                'message' => 'A warranty card names this unit. Void or reassign the card first.',
            ], 422);
        }

        $unit->delete();

        return response()->json(['message' => 'Unit removed from the register.']);
    }

    private function summary(): array
    {
        return [
            'registered' => DeviceUnit::count(),
            'in_stock' => DeviceUnit::where('status', 'in_stock')->count(),
            'sold' => DeviceUnit::where('status', 'sold')->count(),
            'awaiting_installation' => DeviceUnit::where('status', 'sold')
                ->where('installation_required', true)
                ->whereNull('installed_at')
                ->count(),
        ];
    }

    private function row(DeviceUnit $u, bool $detailed = false): array
    {
        $base = [
            'id' => $u->id,
            'label' => $u->label(),
            'identifier' => $u->identifier(),
            'brand' => $u->brand,
            'model_name' => $u->model_name,
            'serial_no' => $u->serial_no,
            'imei_1' => $u->imei_1,
            'imei_2' => $u->imei_2,
            'status' => $u->status,
            'is_third_party' => (bool) $u->is_third_party,
            'customer_id' => $u->customer_id,
            'customer' => $u->customer?->name,
            'customer_phone' => $u->customer?->phone,
            'product_id' => $u->product_id,
            'product' => $u->product?->name,
            'sold_at' => $u->sold_at?->toDateString(),
            'delivered_at' => $u->delivered_at?->toDateString(),
            'installation_required' => (bool) $u->installation_required,
            'installed_at' => $u->installed_at?->toDateString(),
            // Derived, not stored — see DeviceUnit::awaitsInstallation().
            'awaits_installation' => $u->awaitsInstallation(),
            'warranty_months' => $u->warranty_months,
            'last_service_at' => $u->last_service_at?->toIso8601String(),
            'is_active' => (bool) $u->is_active,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'sale_invoice_id' => $u->sale_invoice_id,
            'invoice_number' => $u->saleInvoice?->invoice_number,
            'amc_contract_id' => $u->amc_contract_id,
            'amc' => $u->amcContract ? [
                'contract_number' => $u->amcContract->contract_number,
                'title' => $u->amcContract->title,
                'end_date' => $u->amcContract->end_date?->toDateString(),
                'status' => $u->amcContract->status,
            ] : null,
            'notes' => $u->notes,
        ];
    }
}
