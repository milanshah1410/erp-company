<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionBillingService;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles Razorpay recurring-billing webhooks. Verifies the signature, then
 * updates the central subscriptions + subscription_invoices tables and the
 * owning tenant's status for payment success/failure and cancellation events.
 */
class RazorpayWebhookController extends Controller
{
    public function __construct(
        private RazorpayService $razorpay,
        private SubscriptionBillingService $billing,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');

        if (! $this->razorpay->verifyWebhookSignature($raw, $signature)) {
            Log::warning('Razorpay webhook: invalid signature');
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $payload = $request->json()->all();
        $event = $payload['event'] ?? null;

        $entity = $payload['payload']['subscription']['entity']
            ?? $payload['payload']['payment']['entity']['subscription']
            ?? null;

        $razorpaySubId = is_array($entity) ? ($entity['id'] ?? null) : $entity;

        if (! $razorpaySubId) {
            return response()->json(['message' => 'No subscription in payload; ignored'], 200);
        }

        $subscription = Subscription::where('razorpay_subscription_id', $razorpaySubId)->first();
        if (! $subscription) {
            return response()->json(['message' => 'Unknown subscription; ignored'], 200);
        }

        match ($event) {
            'subscription.activated', 'subscription.charged' => $this->activate($subscription, $payload),
            'subscription.pending', 'payment.failed' => $this->markPastDue($subscription),
            'subscription.halted' => $this->suspend($subscription),
            'subscription.cancelled', 'subscription.completed' => $this->cancel($subscription),
            default => null,
        };

        return response()->json(['message' => 'ok']);
    }

    private function activate(Subscription $subscription, array $payload): void
    {
        $periodEnd = $payload['payload']['subscription']['entity']['current_end'] ?? null;

        $update = [
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : now()->addMonth(),
        ];

        // A plan change parked at change-plan time takes effect on payment.
        if ($subscription->pending_plan_id) {
            $update['plan_id'] = $subscription->pending_plan_id;
            $update['pending_plan_id'] = null;
        }

        $subscription->update($update);

        Tenant::where('id', $subscription->tenant_id)->update(['status' => 'active', 'suspended_by' => null]);

        $amount = $payload['payload']['payment']['entity']['amount'] ?? null;
        $paymentId = $payload['payload']['payment']['entity']['id'] ?? null;
        $currency = $payload['payload']['payment']['entity']['currency'] ?? 'INR';

        if ($amount === null) {
            return;
        }

        // Raised through the billing service so a webhook-recorded charge is a
        // proper GST tax invoice — numbered, with the tax back-computed out of the
        // collected total. Keyed by payment id, so the in-app checkout
        // confirmation and this webhook cannot record the same charge twice.
        if ($paymentId) {
            $this->billing->recordGatewayPayment(
                $subscription->refresh(),
                $paymentId,
                ((int) $amount) / 100,
                $currency,
            );

            return;
        }

        // No payment id to key on (rare, and only on a malformed payload): fall
        // back to an offline-style record rather than dropping the money.
        $this->billing->recordOfflinePayment($subscription->refresh(), ((int) $amount) / 100, [
            'method' => 'other',
            'notes' => 'Razorpay webhook without a payment id',
        ]);
    }

    private function markPastDue(Subscription $subscription): void
    {
        $subscription->update(['status' => 'past_due']);
    }

    private function suspend(Subscription $subscription): void
    {
        $subscription->update(['status' => 'suspended']);
        Tenant::where('id', $subscription->tenant_id)->update(['status' => 'suspended']);
    }

    private function cancel(Subscription $subscription): void
    {
        $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        Tenant::where('id', $subscription->tenant_id)->update(['status' => 'cancelled']);
    }
}
