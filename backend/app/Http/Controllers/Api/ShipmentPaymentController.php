<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentPaymentResource;
use App\Models\Shipment;
use App\Models\ShipmentPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settlements of a shipment's goods value (SRS §24) — export proceeds realised
 * from the overseas buyer (`inbound`) and remittances to the overseas supplier
 * (`outbound`).
 *
 * Every write re-derives the parent shipment's amount_settled / payment_status
 * (Shipment::syncPaymentStatus), so the Pending Payments board is always a
 * straight read of the header rather than a re-summed guess.
 */
class ShipmentPaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = ShipmentPayment::query()
            ->with(['shipment:id,shipment_number,direction', 'bankAccount:id,name', 'creator:id,name']);

        foreach (['shipment_id', 'direction', 'mode'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_number', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('payment_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ShipmentPaymentResource::collection($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'exists:shipments,id'],
            'payment_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['bank_transfer', 'lc', 'cheque', 'cash', 'upi', 'other'])],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $shipment = Shipment::findOrFail($data['shipment_id']);
        // The leg decides which way the money flows: we receive on an export and
        // remit on an import, so the client never has to say.
        $direction = $shipment->direction === 'export' ? 'inbound' : 'outbound';

        $payment = DB::transaction(function () use ($shipment, $data, $direction, $request) {
            $payment = $shipment->payments()->create([
                'direction' => $direction,
                'payment_date' => $data['payment_date'],
                'currency' => $data['currency'] ?? $shipment->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $shipment->exchange_rate,
                'amount' => $data['amount'],
                'mode' => $data['mode'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $shipment->syncPaymentStatus();
            $shipment->logEvent($direction === 'inbound' ? 'payment_received' : 'payment_made', [
                'event_at' => $data['payment_date'],
                'description' => sprintf(
                    '%s %s %s',
                    $direction === 'inbound' ? 'Received' : 'Paid',
                    $payment->currency,
                    number_format((float) $payment->amount, 2),
                ),
                'created_by' => $request->user()?->id,
            ]);

            return $payment;
        });

        return (new ShipmentPaymentResource($payment->load(['shipment:id,shipment_number,direction', 'bankAccount:id,name'])))
            ->response()->setStatusCode(201);
    }

    public function destroy(ShipmentPayment $shipmentPayment)
    {
        $shipment = $shipmentPayment->shipment;

        DB::transaction(function () use ($shipmentPayment, $shipment) {
            $shipmentPayment->delete();
            $shipment?->syncPaymentStatus();
        });

        return response()->json(['message' => 'Payment removed.']);
    }
}
