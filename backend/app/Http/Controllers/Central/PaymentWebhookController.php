<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Server-to-server payment webhooks (SRS §16). One endpoint per gateway; the
 * service verifies the signature against every tenant's account for that
 * gateway, then reconciles the matching transaction. Always answers 200 (unless
 * the body is malformed) so gateways don't hammer retries on benign no-matches.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private PaymentGatewayService $gateways) {}

    public function handle(Request $request, string $gateway): JsonResponse
    {
        $raw = $request->getContent();
        $headers = $request->headers->all();

        try {
            $result = $this->gateways->handleWebhook($gateway, $raw, $headers);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'error'], 200);
        }

        if (empty($result['handled'])) {
            Log::info("Payment webhook [{$gateway}] not handled: ".($result['reason'] ?? 'unknown'));
        }

        return response()->json(['message' => 'ok']);
    }
}
