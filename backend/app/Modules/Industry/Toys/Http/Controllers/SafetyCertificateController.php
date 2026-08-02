<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Toys\Http\Requests\SafetyCertificateRequest;
use App\Modules\Industry\Toys\Models\SafetyCertificate;
use Illuminate\Http\Request;

/**
 * The certificate register — the paperwork a toy shop is asked for.
 *
 * Opened for one of two reasons, and the screen is built around both. Either
 * somebody is filing a new licence, which is ordinary data entry; or somebody is
 * finding out what is about to lapse, which is the reason the register earns its
 * keep. A BIS licence expires on a date nobody has in their diary, and the
 * articles it covers go on sitting on the shelf looking perfectly legal.
 *
 * Expiry is derived on every read rather than stored. A nightly sweep that has
 * not run yet, or was never written, would leave rows saying 'active' about
 * paper that lapsed a fortnight ago — and this register is only worth having if
 * it is right on the morning of an inspection.
 */
class SafetyCertificateController extends Controller
{
    public function index(Request $request)
    {
        $query = SafetyCertificate::query()->with([
            'product:id,sku,name',
            'brand:id,name',
            'supplier:id,code,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('certificate_number', 'like', "%{$search}%")
                    ->orWhere('licence_no', 'like', "%{$search}%")
                    ->orWhere('issued_by', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['standard', 'status', 'product_id', 'brand_id', 'supplier_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The list this register is opened for: what lapses before the month is
        // out, plus whatever already has.
        if ($request->boolean('expiring')) {
            $query->expiringWithin((int) $request->query('within_days', 30));
        }

        // Expiring soonest first — the register's natural work order. Open-ended
        // declarations have no date and belong at the bottom.
        $query->orderByRaw('expires_on IS NULL')->orderBy('expires_on')->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (SafetyCertificate $c) => $this->row($c))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(SafetyCertificate $certificate)
    {
        $certificate->load(['product:id,sku,name', 'brand:id,name', 'supplier:id,code,name']);

        return response()->json(['data' => $this->row($certificate, detailed: true)]);
    }

    public function store(SafetyCertificateRequest $request)
    {
        $certificate = SafetyCertificate::create($request->validated() + [
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(
            ['data' => $this->row($certificate->fresh(['product', 'brand', 'supplier']), detailed: true)],
            201,
        );
    }

    public function update(SafetyCertificateRequest $request, SafetyCertificate $certificate)
    {
        $certificate->update($request->validated());

        return response()->json(
            ['data' => $this->row($certificate->fresh(['product', 'brand', 'supplier']), detailed: true)],
        );
    }

    /**
     * Withdraw a certificate before its date.
     *
     * A separate act from deleting, and the one that actually happens: a licence
     * suspended by the authority, or a test report the shop no longer stands
     * behind, has to stop covering sales *and* stay on the record. Deleting it
     * would leave the shop unable to show what it believed and when.
     */
    public function revoke(Request $request, SafetyCertificate $certificate)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $certificate->forceFill([
            'status' => 'revoked',
            'notes' => trim((string) $certificate->notes."\nWithdrawn: ".($data['reason'] ?? 'no reason given')),
        ])->save();

        return response()->json(['data' => $this->row($certificate->fresh(['product', 'brand', 'supplier']), detailed: true)]);
    }

    public function destroy(SafetyCertificate $certificate)
    {
        $certificate->delete();

        return response()->json(['message' => 'Certificate removed from the register.']);
    }

    /**
     * The tiles above the list.
     *
     * Counted in SQL rather than by loading the register and filtering it in
     * PHP: the same derivation the model does on a single row (see isLive) reads
     * perfectly well as a where clause, and a shop with four thousand articles
     * should not pull four thousand rows to draw five numbers.
     */
    private function summary(): array
    {
        $today = now()->toDateString();

        $live = fn () => SafetyCertificate::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('issued_on')->orWhereDate('issued_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today));

        return [
            'registered' => SafetyCertificate::query()->count(),
            'live' => $live()->count(),
            'expiring_30d' => $live()
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', now()->addDays(30)->toDateString())
                ->count(),
            'expired' => SafetyCertificate::query()
                ->where('status', 'active')
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', $today)
                ->count(),
            'revoked' => SafetyCertificate::query()->where('status', 'revoked')->count(),
        ];
    }

    private function row(SafetyCertificate $c, bool $detailed = false): array
    {
        $base = [
            'id' => $c->id,
            'certificate_number' => $c->certificate_number,
            'standard' => $c->standard,
            'covers' => $c->coverageLabel(),
            'covers_name' => $c->product?->name ?? $c->brand?->name ?? $c->supplier?->name,
            'product_id' => $c->product_id,
            'brand_id' => $c->brand_id,
            'supplier_id' => $c->supplier_id,
            'issued_by' => $c->issued_by,
            'licence_no' => $c->licence_no,
            'issued_on' => $c->issued_on?->toDateString(),
            'expires_on' => $c->expires_on?->toDateString(),
            'days_remaining' => $c->daysRemaining(),
            'status' => $c->derivedStatus(),
            'is_live' => $c->isLive(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'document_path' => $c->document_path,
            'notes' => $c->notes,
            'lapse_reason' => $c->lapseReason(),
        ];
    }
}
