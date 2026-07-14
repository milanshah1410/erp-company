<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesQuotationRequest;
use App\Http\Resources\SalesOrderResource;
use App\Http\Resources\SalesQuotationResource;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Services\DocumentPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesQuotationController extends Controller
{
    public function __construct(
        private readonly DocumentPricingService $pricing,
    ) {}

    public function index(Request $request)
    {
        $query = SalesQuotation::query()->with(['customer', 'warehouse', 'salesOrder']);

        if ($search = $request->query('search')) {
            $query->where('quote_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesQuotationResource::collection($query->paginate($perPage));
    }

    public function store(SalesQuotationRequest $request)
    {
        $quotation = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $quotation = SalesQuotation::create([
                'quote_number' => SalesQuotation::nextNumber(),
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => 'draft',
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($quotation, $data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));

            return $quotation;
        });

        return (new SalesQuotationResource($quotation->load(['customer', 'warehouse', 'items.product'])))
            ->response()->setStatusCode(201);
    }

    public function show(SalesQuotation $salesQuotation)
    {
        return new SalesQuotationResource(
            $salesQuotation->load(['customer', 'warehouse', 'items.product', 'salesOrder'])
        );
    }

    public function update(SalesQuotationRequest $request, SalesQuotation $salesQuotation)
    {
        if ($salesQuotation->status !== 'draft') {
            return response()->json(['message' => 'Only draft quotations can be edited.'], 422);
        }

        DB::transaction(function () use ($request, $salesQuotation) {
            $data = $request->validated();
            $salesQuotation->update([
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $salesQuotation->items()->delete();
            $this->syncItems($salesQuotation, $data['items'], $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
        });

        return new SalesQuotationResource($salesQuotation->load(['customer', 'warehouse', 'items.product']));
    }

    public function destroy(SalesQuotation $salesQuotation)
    {
        if ($salesQuotation->status !== 'draft') {
            return response()->json(['message' => 'Only draft quotations can be deleted.'], 422);
        }
        $salesQuotation->delete();

        return response()->json(['message' => 'Quotation deleted.']);
    }

    public function send(SalesQuotation $salesQuotation)
    {
        if ($salesQuotation->status !== 'draft') {
            return response()->json(['message' => 'Only draft quotations can be sent.'], 422);
        }
        $salesQuotation->update(['status' => 'sent']);

        return new SalesQuotationResource($salesQuotation->load(['customer', 'warehouse', 'items.product']));
    }

    public function accept(SalesQuotation $salesQuotation)
    {
        if ($salesQuotation->status !== 'sent') {
            return response()->json(['message' => 'Only sent quotations can be accepted.'], 422);
        }
        $salesQuotation->update(['status' => 'accepted']);

        return new SalesQuotationResource($salesQuotation->load(['customer', 'warehouse', 'items.product']));
    }

    public function reject(SalesQuotation $salesQuotation)
    {
        if ($salesQuotation->status !== 'sent') {
            return response()->json(['message' => 'Only sent quotations can be rejected.'], 422);
        }
        $salesQuotation->update(['status' => 'rejected']);

        return new SalesQuotationResource($salesQuotation->load(['customer', 'warehouse', 'items.product']));
    }

    /** Convert an accepted quotation into a new draft Sales Order. */
    public function convert(Request $request, SalesQuotation $salesQuotation)
    {
        if ($salesQuotation->status !== 'accepted') {
            return response()->json(['message' => 'Only accepted quotations can be converted.'], 422);
        }

        $salesQuotation->load('items');

        $so = DB::transaction(function () use ($request, $salesQuotation) {
            $so = SalesOrder::create([
                'so_number' => SalesOrder::nextNumber(),
                'customer_id' => $salesQuotation->customer_id,
                'warehouse_id' => $salesQuotation->warehouse_id,
                'status' => 'draft',
                'channel' => 'standard',
                'order_date' => now()->toDateString(),
                'subtotal' => $salesQuotation->subtotal,
                'item_discount_total' => $salesQuotation->item_discount_total,
                'discount_type' => $salesQuotation->discount_type,
                'discount_value' => $salesQuotation->discount_value,
                'discount_amount' => $salesQuotation->discount_amount,
                'tax_amount' => $salesQuotation->tax_amount,
                'round_off' => $salesQuotation->round_off,
                'total' => $salesQuotation->total,
                'notes' => $salesQuotation->notes,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($salesQuotation->items as $item) {
                $so->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_type' => $item->discount_type,
                    'discount_value' => $item->discount_value,
                    'discount_amount' => $item->discount_amount,
                    'tax_percent' => $item->tax_percent,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
                ]);
            }

            $salesQuotation->update(['status' => 'converted', 'sales_order_id' => $so->id]);

            return $so;
        });

        return (new SalesOrderResource($so->load(['customer', 'warehouse', 'items.product'])))
            ->additional(['quote_number' => $salesQuotation->quote_number]);
    }

    /** Create line items and recompute quotation totals (discounts + round-off). */
    private function syncItems(SalesQuotation $quotation, array $items, ?string $discountType = null, float $discountValue = 0): void
    {
        $priced = $this->pricing->price($items, $discountType, $discountValue);

        foreach ($priced['lines'] as $line) {
            $quotation->items()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_percent' => $line['tax_percent'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ]);
        }

        $h = $priced['header'];
        $quotation->update([
            'subtotal' => $h['subtotal'],
            'item_discount_total' => $h['item_discount_total'],
            'discount_type' => $h['discount_type'],
            'discount_value' => $h['discount_value'],
            'discount_amount' => $h['discount_amount'],
            'tax_amount' => $h['tax_amount'],
            'round_off' => $h['round_off'],
            'total' => $h['total'],
        ]);
    }
}
