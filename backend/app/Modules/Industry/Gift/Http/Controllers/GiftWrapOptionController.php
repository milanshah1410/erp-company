<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Gift\Http\Requests\GiftWrapOptionRequest;
use App\Modules\Industry\Gift\Models\GiftWrapOption;
use Illuminate\Http\Request;

/**
 * What the shop offers to wrap things in, and what it charges for it.
 *
 * A small master, but it is where two decisions are made that the rest of the
 * desk depends on: which service product the charge is billed as (so wrapping
 * reaches the bill through the ordinary sales spine and not a second money path),
 * and which stocked material a parcel eats.
 */
class GiftWrapOptionController extends Controller
{
    public function index(Request $request)
    {
        $query = GiftWrapOption::query()->with([
            'product:id,sku,name,selling_price,is_service',
            'material:id,sku,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->query('kind'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (GiftWrapOption $o) => $this->row($o))->toArray()
        );
    }

    public function show(GiftWrapOption $option)
    {
        $option->load(['product:id,sku,name,selling_price,is_service', 'material:id,sku,name']);

        return response()->json(['data' => $this->row($option)]);
    }

    public function store(GiftWrapOptionRequest $request)
    {
        $option = GiftWrapOption::create($request->validated());

        return response()->json(
            ['data' => $this->row($option->fresh(['product', 'material']))],
            201,
        );
    }

    public function update(GiftWrapOptionRequest $request, GiftWrapOption $option)
    {
        $option->update($request->validated());

        return response()->json(['data' => $this->row($option->fresh(['product', 'material']))]);
    }

    public function destroy(GiftWrapOption $option)
    {
        /**
         * Parcels keep the option's name and charge as snapshots, so deleting one
         * would not corrupt them — but a hamper pack pointing at it would silently
         * lose how it is presented, and a shop retiring last season's paper wants
         * it off the picker, not off the record. Deactivating does that.
         */
        if ($option->wraps()->exists()) {
            return response()->json([
                'message' => 'Parcels have been wrapped in this. Switch it off instead of deleting it.',
            ], 422);
        }

        $option->delete();

        return response()->json(['message' => 'Wrapping option removed.']);
    }

    private function row(GiftWrapOption $o): array
    {
        return [
            'id' => $o->id,
            'code' => $o->code,
            'name' => $o->name,
            'kind' => $o->kind,
            'charge' => (float) $o->charge,
            'product_id' => $o->product_id,
            'product' => $o->product?->name,
            // Surfaced because billing wrapping as a *stocked* item would drive
            // that item negative on every sale, and the screen should say so
            // before a shop discovers it at stock-take.
            'product_is_service' => $o->product ? (bool) $o->product->is_service : null,
            'consumes_product_id' => $o->consumes_product_id,
            'material' => $o->material?->name,
            'consume_quantity' => (float) $o->consume_quantity,
            'consumes_stock' => $o->consumesStock(),
            'charge_unbillable' => $o->chargeUnbillable(),
            'turnaround_minutes' => $o->turnaround_minutes,
            'notes' => $o->notes,
            'is_active' => (bool) $o->is_active,
        ];
    }
}
