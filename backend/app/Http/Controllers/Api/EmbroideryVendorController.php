<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmbroideryVendorRequest;
use App\Http\Resources\EmbroideryPaymentResource;
use App\Http\Resources\EmbroideryVendorResource;
use App\Models\EmbroideryVendor;
use Illuminate\Http\Request;

class EmbroideryVendorController extends Controller
{
    public function index(Request $request)
    {
        $query = EmbroideryVendor::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmbroideryVendorResource::collection($query->paginate($perPage));
    }

    public function store(EmbroideryVendorRequest $request)
    {
        $vendor = EmbroideryVendor::create($request->validated());

        return (new EmbroideryVendorResource($vendor))
            ->response()
            ->setStatusCode(201);
    }

    public function show(EmbroideryVendor $embroideryVendor)
    {
        return new EmbroideryVendorResource($embroideryVendor);
    }

    public function update(EmbroideryVendorRequest $request, EmbroideryVendor $embroideryVendor)
    {
        $embroideryVendor->update($request->validated());

        return new EmbroideryVendorResource($embroideryVendor);
    }

    public function destroy(EmbroideryVendor $embroideryVendor)
    {
        if ($embroideryVendor->works()->exists() || $embroideryVendor->payments()->exists()) {
            return response()->json([
                'message' => 'This vendor has dispatch or payment history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $embroideryVendor->delete();

        return response()->json(['message' => 'Embroidery vendor deleted.']);
    }

    /**
     * Chronological vendor ledger: opening balance, then each receipt (credit /
     * earning) and payment (debit / payout), with a running balance. The tail
     * balance equals the vendor's outstanding (unpaid) amount.
     */
    public function ledger(Request $request, EmbroideryVendor $embroideryVendor)
    {
        $receipts = $embroideryVendor->receipts()
            ->with('work:id,work_number,design_number')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->received_at?->toDateString(),
                'sort' => $r->created_at?->toDateTimeString(),
                'type' => 'Received',
                'reference' => $r->work?->work_number,
                'credit' => (float) $r->amount,
                'debit' => 0.0,
            ]);

        $payments = $embroideryVendor->payments()
            ->get()
            ->map(fn ($p) => [
                'date' => $p->payment_date?->toDateString(),
                'sort' => $p->created_at?->toDateTimeString(),
                'type' => 'Payment ('.$p->mode.')',
                'reference' => $p->payment_number,
                'credit' => 0.0,
                'debit' => (float) $p->amount,
            ]);

        $opening = (float) $embroideryVendor->opening_balance;
        $running = $opening;

        $entries = $receipts->concat($payments)
            ->sortBy('sort')
            ->values()
            ->map(function ($row) use (&$running) {
                $running = round($running + $row['credit'] - $row['debit'], 2);
                $row['balance'] = $running;
                unset($row['sort']);

                return $row;
            })
            ->all();

        return response()->json([
            'data' => $entries,
            'summary' => [
                'vendor' => new EmbroideryVendorResource($embroideryVendor),
                'opening_balance' => round($opening, 2),
                'total_earned' => $embroideryVendor->totalEarned(),
                'total_paid' => $embroideryVendor->totalPaid(),
                'outstanding_balance' => $embroideryVendor->outstandingBalance(),
            ],
        ]);
    }

    /** Payments recorded against this vendor, newest first. */
    public function payments(Request $request, EmbroideryVendor $embroideryVendor)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmbroideryPaymentResource::collection(
            $embroideryVendor->payments()->orderByDesc('id')->paginate($perPage)
        );
    }
}
