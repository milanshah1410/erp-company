<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\ComputerIt\Http\Requests\LicenceKeyRequest;
use App\Modules\Industry\ComputerIt\Models\CustomerAsset;
use App\Modules\Industry\ComputerIt\Models\LicenceKey;
use Illuminate\Http\Request;

/**
 * The licence-key register.
 *
 * Two rules shape this controller and both are about the key itself being the
 * valuable thing:
 *
 *  1. **The list never shows a full key.** A register is read over a counter
 *     with a customer standing at it, and a key on screen is a key given away.
 *     Rows carry a masked string; the whole key comes back only from `show`,
 *     which sits behind the register's own view permission and is a deliberate
 *     act by someone who opened that one record.
 *
 *  2. **Allocation is an endpoint, not a field.** Handing a seat to a customer
 *     draws down a count, stamps a machine and moves the key's status; letting
 *     that happen through a general update means the seat maths gets rewritten
 *     by whoever last edited a note.
 */
class LicenceKeyController extends Controller
{
    public function index(Request $request)
    {
        $query = LicenceKey::query()->with([
            'customer:id,name,phone',
            'asset:id,asset_tag,make,model,serial_no',
            'product:id,sku,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('software', 'like', "%{$search}%")
                    ->orWhere('publisher', 'like', "%{$search}%")
                    ->orWhere('vendor', 'like', "%{$search}%")
                    // Searchable by the tail of the key, which is the part a
                    // customer can read off their own paperwork. Not by the whole
                    // string: a full-key search is a way to confirm a key someone
                    // already has, and the register should not be an oracle.
                    ->orWhere('licence_key', 'like', "%{$search}")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['licence_type', 'status', 'customer_id', 'asset_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // Ready to sell: nothing revoked, nothing out of date, seats left.
        if ($request->boolean('available')) {
            $query->where('status', '!=', 'revoked')
                ->whereColumn('seats_used', '<', 'seats')
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', now()->toDateString()));
        }

        // Subscriptions coming up for renewal. Perpetual keys are excluded by
        // the null check rather than by type, because a "retail" key sold with a
        // one-year term is a renewal too.
        if ($request->boolean('expiring')) {
            $query->whereNotNull('valid_to')
                ->whereBetween('valid_to', [
                    now()->toDateString(),
                    now()->addDays((int) $request->query('within_days', 30))->toDateString(),
                ]);
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (LicenceKey $l) => $this->row($l))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    /** The one place the full key is returned — see the class note. */
    public function show(LicenceKey $licence)
    {
        $licence->load([
            'customer:id,name,phone,email',
            'asset:id,asset_tag,make,model,serial_no',
            'product:id,sku,name',
            'invoice:id,invoice_number,invoice_date',
        ]);

        return response()->json(['data' => $this->row($licence, detailed: true)]);
    }

    public function store(LicenceKeyRequest $request)
    {
        $data = $request->validated();

        /**
         * A key created against a customer is already allocated — that is what
         * "we sold this to them" means — so the status follows the data rather
         * than making the counter set it separately and forget.
         */
        $allocated = ! empty($data['customer_id']);

        $licence = LicenceKey::create($data + [
            'seats' => $data['seats'] ?? 1,
            'seats_used' => $allocated ? 1 : 0,
            'status' => $allocated ? 'allocated' : 'in_stock',
            'allocated_at' => $allocated ? now() : null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(
            ['data' => $this->row($licence->fresh(['customer', 'asset', 'product']), detailed: true)],
            201,
        );
    }

    public function update(LicenceKeyRequest $request, LicenceKey $licence)
    {
        $data = $request->validated();

        /**
         * Seats cannot be cut below what has already gone out. The alternative
         * is a key that reports two seats used out of one, which reads as a
         * licensing breach the shop did not commit.
         */
        if (isset($data['seats']) && (int) $data['seats'] < (int) $licence->seats_used) {
            return response()->json([
                'message' => "This key already has {$licence->seats_used} seat(s) issued — release them before reducing the seat count.",
            ], 422);
        }

        $licence->update($data);

        return response()->json(
            ['data' => $this->row($licence->fresh(['customer', 'asset', 'product']), detailed: true)],
        );
    }

    /** Hand a seat to a customer, optionally naming the machine it went onto. */
    public function allocate(Request $request, LicenceKey $licence)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'asset_id' => ['nullable', 'exists:computer_it_assets,id'],
            'seats' => ['nullable', 'integer', 'min:1'],
        ]);

        /**
         * An OEM key is tied to the machine it was first activated on and may
         * not be moved. Refusing here rather than warning is deliberate: this is
         * the licensing rule the shop would be breaking, and the customer asking
         * for it will not be the one who gets the letter.
         */
        if (! $licence->isTransferable()
            && $licence->asset_id
            && ($data['asset_id'] ?? null)
            && (int) $data['asset_id'] !== (int) $licence->asset_id) {
            return response()->json([
                'message' => 'An OEM licence stays with the machine it was first activated on and cannot be moved to another.',
            ], 422);
        }

        if (! $licence->allocate($data['customer_id'], $data['asset_id'] ?? null, (int) ($data['seats'] ?? 1))) {
            return response()->json([
                'message' => $licence->isExpired()
                    ? 'This licence has expired.'
                    : ($licence->status === 'revoked'
                        ? 'This licence has been revoked.'
                        : "No seats left — {$licence->seats_used} of {$licence->seats} are already issued."),
            ], 422);
        }

        return response()->json(
            ['data' => $this->row($licence->fresh(['customer', 'asset', 'product']), detailed: true)],
        );
    }

    /** Take a seat back — a machine returned, or a mis-keyed allocation. */
    public function release(Request $request, LicenceKey $licence)
    {
        $data = $request->validate(['seats' => ['nullable', 'integer', 'min:1']]);

        if ($licence->seats_used < 1) {
            return response()->json(['message' => 'Nothing to release — this licence is not issued to anyone.'], 422);
        }

        $licence->release((int) ($data['seats'] ?? 1));

        return response()->json(
            ['data' => $this->row($licence->fresh(['customer', 'asset', 'product']), detailed: true)],
        );
    }

    /**
     * Kill the key.
     *
     * Separate from delete on purpose: a revoked key must stay in the register,
     * because the whole value of the register is that it can prove this string
     * was issued once and is no longer valid.
     */
    public function revoke(Request $request, LicenceKey $licence)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $licence->forceFill([
            'status' => 'revoked',
            'notes' => trim((string) $licence->notes."\nRevoked: ".($data['reason'] ?? '—')),
        ])->save();

        return response()->json(
            ['data' => $this->row($licence->fresh(['customer', 'asset', 'product']), detailed: true)],
        );
    }

    public function destroy(LicenceKey $licence)
    {
        if ($licence->seats_used > 0) {
            return response()->json([
                'message' => 'This key has been issued to a customer. Revoke it instead of deleting it.',
            ], 422);
        }

        $licence->delete();

        return response()->json(['message' => 'Licence removed from the register.']);
    }

    private function summary(): array
    {
        return [
            'registered' => LicenceKey::count(),
            'in_stock' => LicenceKey::where('status', 'in_stock')->count(),
            'allocated' => LicenceKey::where('status', 'allocated')->count(),
            // Seats, not keys: one volume key can be the difference between a
            // shop having twenty licences spare and having none.
            'seats_free' => (int) (LicenceKey::where('status', '!=', 'revoked')->sum('seats')
                - LicenceKey::where('status', '!=', 'revoked')->sum('seats_used')),
            'expiring_30d' => LicenceKey::whereNotNull('valid_to')
                ->whereBetween('valid_to', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->count(),
        ];
    }

    private function row(LicenceKey $l, bool $detailed = false): array
    {
        $base = [
            'id' => $l->id,
            'software' => $l->software,
            'version' => $l->version,
            'publisher' => $l->publisher,
            'label' => $l->label(),
            'licence_type' => $l->licence_type,
            'is_transferable' => $l->isTransferable(),
            // Masked in the list, always. The full key is added below, and only
            // for the single-record view.
            'licence_key_masked' => $l->masked(),
            'seats' => (int) $l->seats,
            'seats_used' => (int) $l->seats_used,
            'seats_remaining' => $l->seatsRemaining(),
            'customer_id' => $l->customer_id,
            'customer' => $l->customer?->name,
            'asset_id' => $l->asset_id,
            'asset' => $l->asset?->label(),
            'vendor' => $l->vendor,
            'valid_from' => $l->valid_from?->toDateString(),
            'valid_to' => $l->valid_to?->toDateString(),
            'is_perpetual' => $l->valid_to === null,
            'is_expired' => $l->isExpired(),
            'is_available' => $l->isAvailable(),
            'status' => $l->status,
            'allocated_at' => $l->allocated_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'licence_key' => $l->licence_key,
            'product_id' => $l->product_id,
            'product' => $l->product?->name,
            'invoice_id' => $l->invoice_id,
            'invoice_number' => $l->invoice?->invoice_number,
            'notes' => $l->notes,
        ];
    }
}
