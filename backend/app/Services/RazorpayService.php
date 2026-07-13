<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Razorpay REST API (https://api.razorpay.com/v1) for
 * recurring subscriptions. Uses the HTTP client + basic auth (key:secret) so
 * no extra SDK dependency is required. When credentials are absent (local /
 * unconfigured), calls throw a clear "not configured" error that callers can
 * translate into a friendly response.
 */
class RazorpayService
{
    private const BASE = 'https://api.razorpay.com/v1';

    public function configured(): bool
    {
        return ! empty(config('saas.razorpay.key')) && ! empty(config('saas.razorpay.secret'));
    }

    private function client(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Razorpay is not configured. Set RAZORPAY_KEY and RAZORPAY_SECRET.');
        }

        return Http::baseUrl(self::BASE)
            ->withBasicAuth(config('saas.razorpay.key'), config('saas.razorpay.secret'))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Ensure a Razorpay plan exists for our Plan, creating one on first use.
     * Razorpay plan ids are cached on our plan row via feature_limits meta to
     * keep this idempotent; here we just create on demand and return the id.
     */
    public function ensurePlan(Plan $plan): string
    {
        $response = $this->client()->post('/plans', [
            'period' => $plan->billing_cycle === 'yearly' ? 'yearly' : 'monthly',
            'interval' => 1,
            'item' => [
                'name' => $plan->name.' ('.config('app.name').')',
                'amount' => (int) round(((float) $plan->price) * 100), // paise
                'currency' => $plan->currency,
            ],
        ])->throw();

        return $response->json('id');
    }

    public function createCustomer(Tenant $tenant): string
    {
        $response = $this->client()->post('/customers', [
            'name' => $tenant->company_name,
            'email' => $tenant->email,
            'contact' => $tenant->phone,
            'fail_existing' => 0,
        ])->throw();

        return $response->json('id');
    }

    /**
     * Create a recurring subscription for the given Razorpay plan/customer.
     * total_count is the number of billing cycles to charge (12 = one year of
     * monthly billing before renewal).
     */
    public function createSubscription(string $razorpayPlanId, string $customerId, int $totalCount = 12): array
    {
        return $this->client()->post('/subscriptions', [
            'plan_id' => $razorpayPlanId,
            'customer_id' => $customerId,
            'total_count' => $totalCount,
            'customer_notify' => 1,
        ])->throw()->json();
    }

    public function cancelSubscription(string $subscriptionId, bool $atCycleEnd = true): array
    {
        return $this->client()->post("/subscriptions/{$subscriptionId}/cancel", [
            'cancel_at_cycle_end' => $atCycleEnd ? 1 : 0,
        ])->throw()->json();
    }

    /**
     * Verify a webhook payload against the configured webhook secret using the
     * X-Razorpay-Signature header (HMAC-SHA256 of the raw body).
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = config('saas.razorpay.webhook_secret');
        if (empty($secret) || empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
