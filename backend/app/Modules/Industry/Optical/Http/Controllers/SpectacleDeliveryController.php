<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OpticalOrder;
use App\Modules\Industry\Optical\Services\SpectacleDeliveryService;
use App\Modules\Industry\Optical\Services\SpectacleWarrantyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The collection desk: what is ready, who has been told, and who took it.
 *
 * A worklist, not a register. The default view is pairs that are ready and still
 * on the shelf, longest-waiting first, because that is the only question this
 * screen exists to answer. Delivered orders are history and live on the workshop
 * screen.
 */
class SpectacleDeliveryController extends Controller
{
    public function __construct(
        private readonly SpectacleDeliveryService $delivery,
        private readonly SpectacleWarrantyService $warranties,
    ) {}

    /**
     * Ready and not collected, worst first.
     *
     * "Worst" is how long it has been ready rather than how long ago it was
     * ordered: a pair that came back from the lab this morning is not a problem
     * and one that has sat a fortnight is, whatever their order dates say.
     */
    public function board(Request $request)
    {
        $query = OpticalOrder::query()
            ->with(['customer:id,name,phone', 'frame:id,sku,name', 'lens:id,sku,name,lens_type'])
            ->where('status', 'fitted');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        // The chase list: ready, and nobody has ever rung them.
        if ($request->boolean('never_told')) {
            $query->whereNull('notified_at');
        }

        $query->orderByRaw('fitted_at IS NULL')->orderBy('fitted_at');

        $perPage = min((int) $request->query('per_page', 50), 200);

        return response()->json(
            $query->paginate($perPage)->through(fn (OpticalOrder $o) => $this->row($o))->toArray()
            + ['summary' => $this->delivery->boardSummary()],
        );
    }

    /** Tell the customer it is ready. */
    public function notify(OpticalOrder $opticalOrder)
    {
        try {
            $result = $this->delivery->notify($opticalOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->row($opticalOrder->refresh()->load('customer:id,name,phone')),
            'message' => $result['message'],
        ]);
    }

    /**
     * Hand it over: closes the order, raises the invoice, grants the cover.
     *
     * Who collected it is asked for here and nowhere else. It is optional
     * because a shop cannot be made to type a name before it will let a
     * customer leave — but it defaults to the customer's own name, so the
     * common case is recorded rather than blank.
     */
    public function handOver(Request $request, OpticalOrder $opticalOrder)
    {
        $data = $request->validate([
            'delivery_mode' => ['nullable', Rule::in(['counter', 'home', 'courier'])],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_reference' => ['nullable', 'string', 'max:80'],
            'collected_by_name' => ['nullable', 'string', 'max:150'],
            'collected_by_phone' => ['nullable', 'string', 'max:30'],
            'handover_notes' => ['nullable', 'string'],
        ]);

        // A pair going out by courier with no address is a pair that will come
        // back. Checked here rather than in the service because it is a form
        // rule, not a rule of the trade.
        if (in_array($data['delivery_mode'] ?? 'counter', ['home', 'courier'], true)
            && empty($data['delivery_address'])
            && empty($opticalOrder->delivery_address)) {
            return response()->json([
                'message' => 'A pair sent out rather than collected needs an address.',
            ], 422);
        }

        try {
            $result = $this->delivery->handOver($opticalOrder, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $order = $result['order']->load(['customer:id,name,phone', 'frame:id,sku,name', 'lens:id,sku,name,lens_type']);

        return response()->json([
            'data' => $this->row($order),
            'invoice_raised' => (bool) $result['invoice'],
            'invoice_number' => $result['invoice']?->invoice_number,
            'warranties' => $order->warranties()->count(),
            'message' => "Spectacle order {$order->order_number} handed over.",
        ]);
    }

    /** What cover this pair would be given, so the counter can say it out loud. */
    public function warrantyQuote(OpticalOrder $opticalOrder)
    {
        return response()->json(['data' => $this->warranties->quoteFor($opticalOrder)]);
    }

    private function row(OpticalOrder $o): array
    {
        $fittedAt = $o->fitted_at;

        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'customer' => $o->relationLoaded('customer') && $o->customer
                ? ['id' => $o->customer->id, 'name' => $o->customer->name, 'phone' => $o->customer->phone]
                : null,
            'frame' => $o->relationLoaded('frame') ? $o->frame?->name : null,
            'lens' => $o->relationLoaded('lens') ? $o->lens?->name : null,
            'vision_type' => $o->vision_type,
            'total' => (float) $o->total,

            'promised_on' => $o->promised_on?->toDateString(),
            'is_overdue' => $o->isOverdue(),
            'fitted_at' => $fittedAt?->toIso8601String(),
            // The number the desk is actually judged on.
            'days_ready' => $fittedAt ? $fittedAt->diffInDays(now()) : null,
            'notified_at' => $o->notified_at?->toIso8601String(),
            'notify_count' => (int) $o->notify_count,

            'delivery_mode' => $o->delivery_mode,
            'delivery_address' => $o->delivery_address,
            'delivery_reference' => $o->delivery_reference,
            'collected_by_name' => $o->collected_by_name,
            'collected_by_phone' => $o->collected_by_phone,
            'delivered_at' => $o->delivered_at?->toIso8601String(),
            'invoice_id' => $o->invoice_id,
        ];
    }
}
