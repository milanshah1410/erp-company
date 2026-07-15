<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Orchestrates online payment collection against sales invoices (SRS §16):
 * generate a hosted payment link, start checkout at the chosen gateway, and
 * reconcile a successful payment into a {@see \App\Models\PaymentVoucher} (via
 * {@see PaymentService}) so the invoice's paid amount + status roll forward and
 * the receipt appears in the bank/cash books like any other.
 */
class PaymentGatewayService
{
    public function __construct(
        private PaymentGatewayManager $manager,
        private PaymentService $payments,
    ) {}

    /**
     * Create a payable link for an invoice. Amount defaults to the invoice's
     * balance due. The link is not tied to a gateway until the customer starts
     * checkout (so they can pick from the enabled gateways on the pay page).
     */
    public function createLinkForInvoice(Invoice $invoice, array $options = []): PaymentTransaction
    {
        $amount = isset($options['amount']) ? round((float) $options['amount'], 2) : $invoice->balanceDue();

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['This invoice has nothing due.']]);
        }
        if ($amount > $invoice->balanceDue() + 0.005) {
            throw ValidationException::withMessages(['amount' => ["Amount exceeds the balance due of {$invoice->balanceDue()}."]]);
        }

        $ttl = (int) config('payments.link_ttl_minutes', 10080);

        return PaymentTransaction::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => $amount,
            'currency' => $options['currency'] ?? \App\Models\Setting::currencyCode(),
            'status' => 'created',
            'expires_at' => now()->addMinutes($ttl),
            'created_by' => $options['created_by'] ?? null,
        ]);
    }

    /**
     * Begin checkout for a link at a specific gateway account. Returns the pay
     * page's redirect/form instructions and records the gateway order id.
     */
    public function startCheckout(PaymentTransaction $txn, PaymentGatewayAccount $account): array
    {
        if ($txn->isPaid()) {
            throw new RuntimeException('This payment has already been completed.');
        }
        if ($txn->isExpired()) {
            $txn->update(['status' => 'expired']);
            throw new RuntimeException('This payment link has expired.');
        }
        if (! $account->isConfigured()) {
            throw new RuntimeException('The selected payment gateway is not fully configured.');
        }

        $txn->gateway = $account->gateway;
        $txn->payment_gateway_account_id = $account->id;

        $result = $this->manager->driver($account)->createCheckout($txn, $this->context($txn, $account));

        $txn->gateway_order_id = $result['order_id'] ?? $txn->gateway_order_id;
        $txn->checkout_url = $result['flow'] === 'redirect' ? ($result['url'] ?? null) : null;
        $txn->status = 'pending';
        $txn->meta = array_merge($txn->meta ?? [], ['checkout' => $result['meta'] ?? []]);
        $txn->save();

        return $result;
    }

    /** Handle the browser return from a gateway and reconcile the outcome. */
    public function handleReturn(PaymentTransaction $txn, array $params): PaymentTransaction
    {
        $account = $txn->account;
        if (! $account) {
            return $txn;
        }

        $result = $this->manager->driver($account)->verifyCallback($txn, $params);
        $this->reconcile($txn, $result);

        return $txn->refresh();
    }

    /**
     * Handle a server-to-server webhook for a gateway. The tenant is unknown
     * (unauthenticated), so we search across tenants for a matching account,
     * verify the signature, locate the transaction, and reconcile — all inside
     * the resolved tenant's context.
     */
    public function handleWebhook(string $gateway, string $rawBody, array $headers): array
    {
        if (! PaymentGatewayManager::supports($gateway)) {
            return ['handled' => false, 'reason' => 'unknown gateway'];
        }

        $context = app(TenantContext::class);

        $accounts = $context->runWithoutScope(
            fn () => PaymentGatewayAccount::query()->where('gateway', $gateway)->get()
        );

        foreach ($accounts as $account) {
            $driver = $this->manager->driver($account);
            $verified = $driver->verifyWebhook($rawBody, $headers);

            if (empty($verified['verified'])) {
                continue;
            }

            $orderId = $verified['order_id'] ?? null;
            $reference = $verified['reference_id'] ?? null;

            $txn = $context->runWithoutScope(fn () => PaymentTransaction::query()
                ->where('payment_gateway_account_id', $account->id)
                ->where(function ($q) use ($orderId, $reference) {
                    if ($orderId) {
                        $q->orWhere('gateway_order_id', $orderId)->orWhere('public_token', $orderId);
                    }
                    if ($reference) {
                        $q->orWhere('public_token', $reference)->orWhere('gateway_order_id', $reference);
                    }
                })
                ->first());

            if (! $txn) {
                continue;
            }

            return $context->runWith($txn->tenant_id, function () use ($txn, $verified) {
                // Re-load inside tenant scope so relations/writes are tenant-safe.
                $scoped = PaymentTransaction::findOrFail($txn->id);
                $this->reconcile($scoped, $verified);

                return ['handled' => true, 'status' => $scoped->status];
            });
        }

        Log::info("Payment webhook for {$gateway}: no matching verified transaction.");

        return ['handled' => false, 'reason' => 'no match'];
    }

    /**
     * Apply a normalised gateway result to a transaction. Idempotent: a paid
     * transaction is never booked twice. On success it books a customer receipt
     * voucher allocated to the invoice.
     */
    public function reconcile(PaymentTransaction $txn, array $result): void
    {
        if ($txn->isPaid()) {
            return;
        }

        $status = $result['status'] ?? 'pending';

        if ($status !== 'paid') {
            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                $txn->update([
                    'status' => $status,
                    'gateway_payment_id' => $result['payment_id'] ?? $txn->gateway_payment_id,
                    'failure_reason' => $result['raw']['error_description'] ?? null,
                ]);
            }

            return;
        }

        DB::transaction(function () use ($txn, $result) {
            $paidAmount = round((float) ($result['amount'] ?? $txn->amount), 2);
            $voucher = null;

            if ($txn->invoice_id && $txn->customer_id) {
                $invoice = Invoice::find($txn->invoice_id);
                $allocatable = $invoice
                    ? min($paidAmount, $invoice->balanceDue())
                    : 0.0;

                $voucher = $this->payments->record([
                    'direction' => 'inbound',
                    'party_type' => 'customer',
                    'party_id' => $txn->customer_id,
                    'amount' => $paidAmount,
                    'mode' => 'bank',
                    'reference_no' => $result['payment_id'] ?? $txn->gateway_order_id,
                    'bank_name' => config("payments.gateways.{$txn->gateway}.label", ucfirst((string) $txn->gateway)),
                    'payment_date' => now()->toDateString(),
                    'notes' => 'Online payment ('.ucfirst((string) $txn->gateway).') for '.($invoice?->invoice_number ?? 'invoice'),
                    'created_by' => $txn->created_by,
                    'allocations' => $allocatable > 0 ? [[
                        'allocatable_type' => 'invoice',
                        'allocatable_id' => $txn->invoice_id,
                        'amount' => $allocatable,
                    ]] : [],
                ]);
            }

            $txn->update([
                'status' => 'paid',
                'gateway_payment_id' => $result['payment_id'] ?? $txn->gateway_payment_id,
                'payment_voucher_id' => $voucher?->id,
                'paid_at' => now(),
            ]);
        });
    }

    /** Build the checkout context (return/webhook URLs + customer details). */
    private function context(PaymentTransaction $txn, PaymentGatewayAccount $account): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $customer = $txn->customer;

        return [
            'return_url' => $base.'/api/pay/'.$txn->public_token.'/callback',
            'webhook_url' => $base.'/api/webhooks/payments/'.$account->gateway,
            'description' => $txn->invoice ? ('Invoice '.$txn->invoice->invoice_number) : 'Invoice payment',
            'currency' => $txn->currency,
            'customer' => [
                'name' => $customer?->name,
                'email' => $customer?->email,
                'phone' => $customer?->phone,
            ],
        ];
    }
}
