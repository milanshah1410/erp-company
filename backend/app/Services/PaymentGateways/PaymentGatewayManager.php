<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways;

use App\Models\PaymentGatewayAccount;
use App\Services\PaymentGateways\Contracts\PaymentGatewayDriver;
use App\Services\PaymentGateways\Drivers\CashfreeGatewayDriver;
use App\Services\PaymentGateways\Drivers\CcavenueGatewayDriver;
use App\Services\PaymentGateways\Drivers\PayuGatewayDriver;
use App\Services\PaymentGateways\Drivers\PhonePeGatewayDriver;
use App\Services\PaymentGateways\Drivers\RazorpayGatewayDriver;
use App\Services\PaymentGateways\Drivers\StripeGatewayDriver;
use InvalidArgumentException;

/**
 * Resolves the concrete {@see PaymentGatewayDriver} for a configured gateway
 * account. The gateway key (razorpay/cashfree/…) picks the driver class; the
 * account supplies its credentials + test/live endpoint.
 */
class PaymentGatewayManager
{
    private const DRIVERS = [
        'razorpay' => RazorpayGatewayDriver::class,
        'cashfree' => CashfreeGatewayDriver::class,
        'phonepe' => PhonePeGatewayDriver::class,
        'payu' => PayuGatewayDriver::class,
        'ccavenue' => CcavenueGatewayDriver::class,
        'stripe' => StripeGatewayDriver::class,
    ];

    public function driver(PaymentGatewayAccount $account): PaymentGatewayDriver
    {
        $class = self::DRIVERS[$account->gateway] ?? null;

        if (! $class) {
            throw new InvalidArgumentException("Unsupported payment gateway: {$account->gateway}.");
        }

        return new $class($account);
    }

    /** Whether a gateway key is known / supported. */
    public static function supports(string $gateway): bool
    {
        return isset(self::DRIVERS[$gateway]);
    }

    /** All supported gateway keys. */
    public static function gateways(): array
    {
        return array_keys(self::DRIVERS);
    }
}
