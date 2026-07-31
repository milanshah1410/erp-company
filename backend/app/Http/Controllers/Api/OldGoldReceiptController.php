<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OldGoldReceipt;
use App\Services\JewelleryPricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Old gold taken in from customers.
 *
 * A receipt is created when the scrap is weighed, and stays open until it is
 * set off against a bill — a customer commonly sells on one visit and buys on
 * the next, so an unsettled receipt is a credit they are holding.
 */
class OldGoldReceiptController extends Controller
{
    public function __construct(private readonly JewelleryPricingService $pricing) {}

    public function index(Request $request)
    {
        $query = OldGoldReceipt::query()->with(['customer:id,name,phone', 'invoice:id,invoice_number']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }
        foreach (['customer_id', 'metal', 'purity'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        // The credit ledger: scrap taken in but not yet used against a bill.
        if ($request->boolean('unsettled_only')) {
            $query->whereNull('invoice_id');
        }
        if ($request->filled('from')) {
            $query->where('received_on', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('received_on', '<=', $request->query('to'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = $query->paginate($perPage);

        return response()->json(
            $page->through(fn (OldGoldReceipt $r) => $this->row($r))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    /**
     * Take scrap in. The counter enters weights and the deduction; the value is
     * computed here rather than posted, so a receipt can never disagree with
     * the rate card it was supposedly priced from.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'metal' => ['required', Rule::in(['gold', 'silver', 'platinum'])],
            'purity' => ['required', 'string', 'max:10'],
            'description' => ['nullable', 'string', 'max:255'],
            'gross_weight' => ['required', 'numeric', 'min:0.001'],
            'net_weight' => ['required', 'numeric', 'min:0.001', 'lte:gross_weight'],
            'deduction_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'received_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $purity = strtoupper($data['purity']);
        $receivedOn = $data['received_on'] ?? now()->toDateString();

        try {
            $valued = $this->pricing->valueOldGold(
                $data['metal'],
                $purity,
                (float) $data['net_weight'],
                (float) ($data['deduction_percent'] ?? 0),
                $receivedOn,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['purity' => [$e->getMessage()]]);
        }

        $receipt = OldGoldReceipt::create([
            'receipt_number' => OldGoldReceipt::nextNumber(),
            'customer_id' => $data['customer_id'] ?? null,
            'metal' => $data['metal'],
            'purity' => $purity,
            'fineness' => $valued['fineness'],
            'description' => $data['description'] ?? null,
            'gross_weight' => $data['gross_weight'],
            'net_weight' => $data['net_weight'],
            'deduction_percent' => $data['deduction_percent'] ?? 0,
            'chargeable_weight' => $valued['chargeable_weight'],
            'rate_per_gram' => $valued['rate_per_gram'],
            'amount' => $valued['amount'],
            'received_on' => $receivedOn,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($receipt->load('customer:id,name,phone'))], 201);
    }

    public function show(OldGoldReceipt $oldGoldReceipt)
    {
        return response()->json([
            'data' => $this->row($oldGoldReceipt->load(['customer:id,name,phone', 'invoice:id,invoice_number'])),
        ]);
    }

    public function destroy(OldGoldReceipt $oldGoldReceipt)
    {
        if ($oldGoldReceipt->isSettled()) {
            return response()->json([
                'message' => 'This receipt has been set off against an invoice and cannot be deleted.',
            ], 422);
        }

        $oldGoldReceipt->delete();

        return response()->json(['message' => 'Receipt deleted.']);
    }

    /**
     * Value scrap without committing it — the counter quotes a customer before
     * agreeing to take the piece in.
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'metal' => ['required', Rule::in(['gold', 'silver', 'platinum'])],
            'purity' => ['required', 'string', 'max:10'],
            'net_weight' => ['required', 'numeric', 'min:0.001'],
            'deduction_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $valued = $this->pricing->valueOldGold(
                $data['metal'],
                strtoupper($data['purity']),
                (float) $data['net_weight'],
                (float) ($data['deduction_percent'] ?? 0),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['purity' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $valued]);
    }

    /** Weight and value taken in, and what is still owed back as credit. */
    private function summary(): array
    {
        $row = OldGoldReceipt::query()
            ->selectRaw('COALESCE(SUM(chargeable_weight), 0) as weight')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoice_id IS NULL THEN amount ELSE 0 END), 0) as unsettled')
            ->first();

        return [
            'weight' => round((float) $row->weight, 3),
            'amount' => round((float) $row->amount, 2),
            'unsettled' => round((float) $row->unsettled, 2),
        ];
    }

    private function row(OldGoldReceipt $r): array
    {
        return [
            'id' => $r->id,
            'receipt_number' => $r->receipt_number,
            'customer_id' => $r->customer_id,
            'customer' => $r->relationLoaded('customer') && $r->customer
                ? ['id' => $r->customer->id, 'name' => $r->customer->name, 'phone' => $r->customer->phone]
                : null,
            'metal' => $r->metal,
            'purity' => $r->purity,
            'fineness' => $r->fineness,
            'description' => $r->description,
            'gross_weight' => (float) $r->gross_weight,
            'net_weight' => (float) $r->net_weight,
            'deduction_percent' => (float) $r->deduction_percent,
            'chargeable_weight' => (float) $r->chargeable_weight,
            'rate_per_gram' => (float) $r->rate_per_gram,
            'amount' => (float) $r->amount,
            'received_on' => $r->received_on?->toDateString(),
            'is_settled' => $r->isSettled(),
            'invoice' => $r->relationLoaded('invoice') ? $r->invoice?->invoice_number : null,
            'notes' => $r->notes,
        ];
    }
}
