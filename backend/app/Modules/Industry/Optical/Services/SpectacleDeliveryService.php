<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Services;

use App\Models\Invoice;
use App\Models\OpticalOrder;
use App\Models\Setting;
use App\Services\OpticalService;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The collection desk.
 *
 * Core's workshop flow ends at `delivered`, and for a long time that was the
 * whole of "delivery": a status and a timestamp. Two things were missing, and
 * both are things a shop is actually asked about afterwards.
 *
 * **Was the customer ever told?** A finished pair sitting in a drawer for three
 * weeks is almost never a lab problem — it is a call nobody made. So being ready
 * and telling somebody are separate acts here, and the second one is counted:
 * the desk can see it has rung twice and still nobody has come.
 *
 * **Who took it?** Spectacles are collected by somebody other than the patient
 * far more often than not — a son, a driver, a neighbour with the receipt. The
 * shop only ever needs this on the day something has gone wrong, which is
 * exactly why it must be captured on the day nothing has.
 *
 * Billing is not re-implemented here. Handing over calls core's OpticalService,
 * which raises the sales order and invoice on the `optical` channel the same way
 * the workshop screen does — one route to the money, whichever screen the
 * counter happened to use. The warranty cards are not raised here either: they
 * hang off the status change through OpticalOrderObserver, so a pair delivered
 * from the core workshop screen gets the same cover as one delivered from here.
 */
class SpectacleDeliveryService
{
    /** The Settings key holding the "your spectacles are ready" text. */
    public const SMS_TEMPLATE = 'sms_tpl_optical_ready';

    public function __construct(
        private readonly OpticalService $optical,
        private readonly SmsService $sms,
    ) {}

    /**
     * Tell the customer the pair is ready.
     *
     * Never throws on a gateway failure — SmsService captures that on the message
     * row — but it does refuse to send to a pair that is not actually ready, and
     * it refuses when there is no number to send to. Both are counter mistakes
     * worth surfacing rather than swallowing.
     *
     * @return array{sent: bool, message: string}
     */
    public function notify(OpticalOrder $order): array
    {
        if (! $this->isReady($order)) {
            throw new RuntimeException('This pair is not ready yet — fit the lenses before telling the customer.');
        }

        $order->loadMissing('customer:id,name,phone');
        $phone = $order->customer?->phone;

        if (! $phone) {
            throw new RuntimeException('This customer has no phone number on record.');
        }

        $this->sms->send($phone, $this->readyMessage($order), [
            'customer_id' => $order->customer_id,
            'template' => self::SMS_TEMPLATE,
        ]);

        $order->forceFill([
            'notified_at' => now(),
            'notify_count' => (int) $order->notify_count + 1,
        ])->save();

        return [
            'sent' => true,
            'message' => "Told {$order->customer?->name} that {$order->order_number} is ready.",
        ];
    }

    /**
     * Hand the pair over.
     *
     * One act at the counter, three consequences: the order closes, the money is
     * raised, and (through the observer) cover is granted. Doing it in one
     * transaction is what stops a shop from having spectacles that left the
     * premises with no invoice behind them.
     *
     * @param  array<string, mixed>  $details
     * @return array{order: OpticalOrder, invoice: Invoice|null}
     */
    public function handOver(OpticalOrder $order, array $details, ?int $userId = null): array
    {
        if ($order->status === 'delivered') {
            throw new RuntimeException("Spectacle order {$order->order_number} has already been handed over.");
        }
        if ($order->isClosed()) {
            throw new RuntimeException("Spectacle order {$order->order_number} was cancelled and cannot be handed over.");
        }
        if (! $this->isReady($order)) {
            throw new RuntimeException('The lenses are not fitted yet. Move the order to fitted before handing it over.');
        }

        return DB::transaction(function () use ($order, $details, $userId) {
            $order->forceFill(array_filter([
                'status' => 'delivered',
                'delivered_at' => now(),
                'delivery_mode' => $details['delivery_mode'] ?? $order->delivery_mode ?? 'counter',
                'delivery_address' => $details['delivery_address'] ?? $order->delivery_address,
                'delivery_reference' => $details['delivery_reference'] ?? $order->delivery_reference,
                'collected_by_name' => $details['collected_by_name'] ?? $order->customer?->name,
                'collected_by_phone' => $details['collected_by_phone'] ?? null,
                'handover_notes' => $details['handover_notes'] ?? null,
            ], fn ($v) => $v !== null))->save();

            // Core owns the money. Idempotent there, so a double-click at the
            // counter cannot bill the pair twice.
            $invoice = $this->optical->bill($order->refresh(), $userId);

            return ['order' => $order->refresh(), 'invoice' => $invoice];
        });
    }

    /**
     * What the desk opens to: pairs that are ready and still here, worst first.
     *
     * "Worst" is how long it has been ready rather than how long ago it was
     * ordered — a pair that came back from the lab this morning is not the
     * problem, and one that has been on the shelf a fortnight is.
     *
     * @return array<string, mixed>
     */
    public function boardSummary(): array
    {
        $ready = OpticalOrder::query()->where('status', 'fitted');

        return [
            'ready' => (clone $ready)->count(),
            'never_told' => (clone $ready)->whereNull('notified_at')->count(),
            'waiting_over_week' => (clone $ready)
                ->whereNotNull('fitted_at')
                ->where('fitted_at', '<', now()->subWeek())
                ->count(),
            'at_lab' => OpticalOrder::query()->whereIn('status', ['ordered', 'at_lab'])->count(),
            'handed_over_today' => OpticalOrder::query()
                ->where('status', 'delivered')
                ->whereDate('delivered_at', now()->toDateString())
                ->count(),
        ];
    }

    /** Are the lenses actually in the frame? */
    private function isReady(OpticalOrder $order): bool
    {
        return $order->status === 'fitted';
    }

    /**
     * The message. Read from Settings so a shop writes its own — the fallback is
     * deliberately plain, and names the order because a customer ringing back
     * has nothing else to quote.
     */
    private function readyMessage(OpticalOrder $order): string
    {
        $template = Setting::get(self::SMS_TEMPLATE)
            ?: 'Your spectacles ({order}) are ready for collection. Please bring this message with you.';

        return str_replace(
            ['{order}', '{customer}', '{shop}'],
            [
                (string) $order->order_number,
                (string) ($order->customer?->name ?? ''),
                (string) (Setting::get('company_name') ?: ''),
            ],
            $template,
        );
    }
}
