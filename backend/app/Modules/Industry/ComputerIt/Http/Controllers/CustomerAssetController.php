<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use App\Modules\Industry\ComputerIt\Http\Requests\CustomerAssetRequest;
use App\Modules\Industry\ComputerIt\Models\CustomerAsset;
use Illuminate\Http\Request;

/**
 * The install base — every machine this shop is responsible for.
 *
 * The register answers three questions that a sales history cannot: what does
 * this customer actually run, where is each machine sitting, and what is still
 * covered on it. That last one is why cover and AMC are summarised on every row
 * rather than left to a second lookup — the answer is needed at the moment a
 * customer rings, not afterwards.
 */
class CustomerAssetController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerAsset::query()->with([
            'customer:id,code,name,phone',
            'product:id,sku,name',
            'amcContract:id,contract_number,title,start_date,end_date,status,visits_allowed,visits_used',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('asset_tag', 'like', "%{$search}%")
                    ->orWhere('serial_no', 'like', "%{$search}%")
                    ->orWhere('make', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('assigned_user', 'like', "%{$search}%")
                    ->orWhere('site', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['category', 'status', 'customer_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Machines on a live contract — the list an engineer's week is planned
        // from, and the one a shop chases renewals against.
        if ($request->boolean('on_amc')) {
            $query->whereNotNull('amc_contract_id');
        }

        // A preventive visit is due, or falls inside the window.
        if ($request->boolean('service_due')) {
            $query->whereNotNull('next_service_due_at')
                ->whereDate('next_service_due_at', '<=', now()->addDays((int) $request->query('within_days', 0)));
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (CustomerAsset $a) => $this->row($a))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(CustomerAsset $asset)
    {
        $asset->load([
            'customer:id,code,name,phone,email',
            'product:id,sku,name',
            'saleInvoice:id,invoice_number,invoice_date,total',
            'amcContract:id,contract_number,title,start_date,end_date,status,visits_allowed,visits_used',
            'warranties',
            'licenceKeys:id,asset_id,software,version,licence_type,status,valid_to,seats,seats_used',
        ]);

        return response()->json(['data' => $this->row($asset, detailed: true)]);
    }

    /**
     * Everything that has ever happened to this machine, newest first.
     *
     * Calls and job cards together, because the customer asking "what have you
     * done to this thing?" does not distinguish between a phone call that fixed
     * it and a week on the bench, and a history that shows only one of the two
     * reads as a shop that did nothing for months at a time.
     */
    public function history(CustomerAsset $asset)
    {
        $calls = $asset->serviceCalls()
            ->with('engineer:id,employee_code,first_name,last_name')
            ->orderByDesc('logged_at')
            ->limit(100)
            ->get()
            ->map(fn ($c) => [
                'kind' => 'call',
                'id' => $c->id,
                'reference' => $c->call_number,
                'at' => $c->logged_at?->toIso8601String(),
                'status' => $c->status,
                'type' => $c->type,
                'summary' => $c->complaint,
                'resolution' => $c->resolution,
                'engineer' => $c->engineer?->fullName(),
                'chargeable' => (bool) $c->is_chargeable,
                'service_job_id' => $c->service_job_id,
            ]);

        $jobs = ServiceJob::where('it_asset_id', $asset->id)
            ->orderByDesc('received_at')
            ->limit(100)
            ->get()
            ->map(fn (ServiceJob $j) => [
                'kind' => 'job',
                'id' => $j->id,
                'reference' => $j->job_number,
                'at' => $j->received_at?->toIso8601String(),
                'status' => $j->status,
                'type' => $j->is_warranty ? 'warranty' : 'chargeable',
                'summary' => $j->complaint,
                'resolution' => $j->resolution,
                'total' => (float) $j->total,
                'it_warranty_id' => $j->it_warranty_id,
            ]);

        $timeline = $calls->concat($jobs)
            ->sortByDesc(fn (array $row) => $row['at'] ?? '')
            ->values();

        return response()->json([
            'data' => $timeline,
            'asset' => ['id' => $asset->id, 'label' => $asset->label(), 'tag' => $asset->asset_tag],
        ]);
    }

    public function store(CustomerAssetRequest $request)
    {
        $data = $request->validated();

        $asset = CustomerAsset::create($data + [
            // Generated, never typed: the tag is what goes on the sticker and
            // what an engineer reads back down a phone line.
            'asset_tag' => CustomerAsset::nextNumber(),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(
            ['data' => $this->row($asset->fresh(['customer', 'product', 'amcContract']), detailed: true)],
            201,
        );
    }

    public function update(CustomerAssetRequest $request, CustomerAsset $asset)
    {
        $asset->update($request->validated());

        return response()->json(
            ['data' => $this->row($asset->fresh(['customer', 'product', 'amcContract']), detailed: true)],
        );
    }

    public function destroy(CustomerAsset $asset)
    {
        /**
         * A machine that has been worked on is history, not a mistake. Deleting
         * it would orphan the job cards that billed for that work (the FK is
         * nullOnDelete, so they would silently lose which machine they were
         * about), and a shop asked in two years what it did to a customer's
         * laptop would have no answer.
         */
        if ($asset->serviceJobs()->exists() || $asset->serviceCalls()->exists()) {
            return response()->json([
                'message' => 'This machine has calls or job cards against it. Retire it instead of deleting it.',
            ], 422);
        }

        $asset->delete();

        return response()->json(['message' => 'Machine removed from the register.']);
    }

    private function summary(): array
    {
        return [
            'registered' => CustomerAsset::count(),
            'deployed' => CustomerAsset::where('status', 'deployed')->count(),
            'in_service' => CustomerAsset::where('status', 'in_service')->count(),
            'on_amc' => CustomerAsset::whereNotNull('amc_contract_id')->count(),
            'service_due' => CustomerAsset::whereNotNull('next_service_due_at')
                ->whereDate('next_service_due_at', '<=', now())
                ->count(),
        ];
    }

    private function row(CustomerAsset $a, bool $detailed = false): array
    {
        $warranty = $a->liveWarranty();

        $base = [
            'id' => $a->id,
            'asset_tag' => $a->asset_tag,
            'label' => $a->label(),
            'customer_id' => $a->customer_id,
            'customer' => $a->customer?->name,
            'category' => $a->category,
            'make' => $a->make,
            'model' => $a->model,
            'serial_no' => $a->serial_no,
            'site' => $a->site,
            'department' => $a->department,
            'assigned_user' => $a->assigned_user,
            'status' => $a->status,
            'is_active' => (bool) $a->is_active,
            'purchase_date' => $a->purchase_date?->toDateString(),
            'installed_on' => $a->installed_on?->toDateString(),
            'last_service_at' => $a->last_service_at?->toIso8601String(),
            'next_service_due_at' => $a->next_service_due_at?->toDateString(),
            'is_service_due' => $a->isServiceDue(),
            /**
             * Cover is computed on read rather than stored, for the same reason
             * the warranty register computes it: a row can sit at 'active' while
             * its end date quietly passed last week, and the counter should be
             * told the truth without waiting for someone to run a sweep.
             */
            'warranty' => $warranty ? [
                'id' => $warranty->id,
                'warranty_number' => $warranty->warranty_number,
                'end_date' => $warranty->end_date?->toDateString(),
                'days_remaining' => $warranty->daysRemaining(),
                'service_level' => $warranty->service_level,
                'covers_onsite' => $warranty->coversOnsite(),
            ] : null,
            'amc_contract_id' => $a->amc_contract_id,
            'amc' => $a->amcContract ? [
                'id' => $a->amcContract->id,
                'contract_number' => $a->amcContract->contract_number,
                'title' => $a->amcContract->title,
                'end_date' => $a->amcContract->end_date?->toDateString(),
                'visits_remaining' => $a->amcContract->visitsRemaining(),
                'is_live' => $a->amcContract->coversToday(),
            ] : null,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'product_id' => $a->product_id,
            'product' => $a->product?->name,
            'specification' => $a->specification,
            'os' => $a->os,
            'mac_address' => $a->mac_address,
            'sale_invoice_id' => $a->sale_invoice_id,
            'sale_invoice_number' => $a->saleInvoice?->invoice_number,
            'service_interval_days' => $a->service_interval_days,
            'notes' => $a->notes,
            'warranties' => $a->relationLoaded('warranties')
                ? $a->warranties->map(fn ($w) => [
                    'id' => $w->id,
                    'warranty_number' => $w->warranty_number,
                    'type' => $w->type,
                    'provider' => $w->provider,
                    'service_level' => $w->service_level,
                    'start_date' => $w->start_date?->toDateString(),
                    'end_date' => $w->end_date?->toDateString(),
                    'status' => $w->status,
                    'is_live' => $w->covers(),
                    'claims_count' => (int) $w->claims_count,
                ])->values()
                : [],
            'licence_keys' => $a->relationLoaded('licenceKeys')
                ? $a->licenceKeys->map(fn ($l) => [
                    'id' => $l->id,
                    'software' => trim($l->software.' '.(string) $l->version),
                    'licence_type' => $l->licence_type,
                    'status' => $l->status,
                    'valid_to' => $l->valid_to?->toDateString(),
                    'seats' => (int) $l->seats,
                    'seats_used' => (int) $l->seats_used,
                ])->values()
                : [],
        ];
    }
}
