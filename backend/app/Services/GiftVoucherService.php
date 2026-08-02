<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GiftVoucher;
use App\Models\GiftVoucherRedemption;
use App\Models\Invoice;
use App\Models\SalesOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gift vouchers as a tender.
 *
 * Everything the counter does with a voucher goes through here, so the two rules
 * that make a voucher safe are stated once:
 *
 *  1. **A voucher can never pay more than it holds, or more than the bill.**
 *     Quoting is separated from spending (`quote` → `spend`) so an over-tendered
 *     bill is refused before a single row is written, the same shape
 *     LoyaltyService uses for points.
 *
 *  2. **Balance is derived arithmetic, never a running edit.** Every movement
 *     writes a signed redemption row and recomputes the balance from face value
 *     minus their sum, under a row lock. Two tills redeeming the same card at the
 *     same moment therefore cannot both see ₹500 and both spend it.
 */
class GiftVoucherService
{
    /** Find a voucher by the code typed at the till. Case and spacing forgiven. */
    public function findByCode(string $code): ?GiftVoucher
    {
        $normalised = strtoupper(preg_replace('/\s+/', '', $code) ?: '');

        return $normalised === '' ? null : GiftVoucher::where('code', $normalised)->first();
    }

    /**
     * What these vouchers can put against a bill of this size.
     *
     * Applied in the order given, each drawing down what the earlier ones left of
     * the bill — so two cards on one sale behave the way the customer expects.
     * Refuses loudly on a bad code, a dead voucher or a duplicate, because every
     * one of those is a mistake the counter has to see *now* rather than a
     * silently ignored line on a receipt.
     *
     * @param  list<string>  $codes
     * @return array{total: float, lines: list<array{voucher: GiftVoucher, amount: float}>}
     */
    public function quote(array $codes, float $billTotal): array
    {
        $lines = [];
        $seen = [];
        $remaining = round($billTotal, 2);

        foreach ($codes as $code) {
            $voucher = $this->findByCode((string) $code);

            if (! $voucher) {
                throw new RuntimeException("No gift voucher matches the code \"{$code}\".");
            }

            if (in_array($voucher->id, $seen, true)) {
                throw new RuntimeException("Voucher {$voucher->code} has been entered twice on this bill.");
            }

            if ($reason = $voucher->refusalReason()) {
                throw new RuntimeException("Voucher {$voucher->code}: {$reason}");
            }

            $seen[] = $voucher->id;

            if ($remaining <= 0) {
                throw new RuntimeException(
                    "Voucher {$voucher->code} is not needed — the bill is already covered.",
                );
            }

            $amount = round(min((float) $voucher->balance, $remaining), 2);
            $remaining = round($remaining - $amount, 2);

            $lines[] = ['voucher' => $voucher, 'amount' => $amount];
        }

        return [
            'total' => round((float) array_sum(array_column($lines, 'amount')), 2),
            'lines' => $lines,
        ];
    }

    /**
     * Draw the quoted amounts down and record where they went.
     *
     * Call inside the sale's transaction, after the invoice exists — the
     * redemption rows point at it, which is what makes a voucher traceable to the
     * goods it bought.
     *
     * @param  list<array{voucher: GiftVoucher, amount: float}>  $lines
     * @return list<GiftVoucherRedemption>
     */
    public function spend(array $lines, Invoice $invoice, ?SalesOrder $salesOrder = null, ?int $userId = null): array
    {
        $out = [];

        foreach ($lines as $line) {
            if ($line['amount'] <= 0) {
                continue;
            }

            $out[] = $this->move(
                $line['voucher'],
                $line['amount'],
                $invoice,
                $salesOrder,
                "Redeemed against invoice {$invoice->invoice_number}",
                $userId,
            );
        }

        return $out;
    }

    /**
     * Put voucher value back when goods bought with it are returned.
     *
     * Refunding voucher-paid goods in cash would turn a gift voucher into a cash
     * withdrawal, which is precisely what no shop allows — so the value goes back
     * onto the cards it came off, newest first, and the caller reduces the money
     * refund by whatever was restored. (The caller must do that: crediting the
     * card *and* handing over the cash would refund twice.)
     *
     * Capped twice over — at the amount being credited, and at what each card
     * actually paid net of earlier partial returns — so repeated returns against
     * one invoice can never restore more than was spent.
     *
     * @return float the amount actually put back
     */
    public function reverseForInvoice(
        Invoice $invoice,
        float $creditedAmount,
        ?string $reference = null,
        ?int $userId = null,
    ): float {
        $creditedAmount = round($creditedAmount, 2);

        if ($creditedAmount <= 0) {
            return 0.0;
        }

        // Net spend per voucher on this invoice: spends less anything already
        // reversed. Doing it in one grouped read keeps a five-return history from
        // becoming five queries per card.
        $net = GiftVoucherRedemption::where('invoice_id', $invoice->id)
            ->selectRaw('gift_voucher_id, SUM(amount) as net')
            ->groupBy('gift_voucher_id')
            ->having('net', '>', 0)
            ->orderByDesc('gift_voucher_id')
            ->pluck('net', 'gift_voucher_id');

        if ($net->isEmpty()) {
            return 0.0;
        }

        /** @var Collection<int, GiftVoucher> $vouchers */
        $vouchers = GiftVoucher::whereIn('id', $net->keys())->get()->keyBy('id');

        $restored = 0.0;
        $left = $creditedAmount;

        foreach ($net as $voucherId => $spent) {
            if ($left <= 0) {
                break;
            }

            $voucher = $vouchers->get($voucherId);

            if (! $voucher) {
                continue;
            }

            $amount = round(min((float) $spent, $left), 2);

            $this->move(
                $voucher,
                -$amount,
                $invoice,
                null,
                'Restored on return'.($reference ? " {$reference}" : '')
                    ." against invoice {$invoice->invoice_number}",
                $userId,
            );

            $restored = round($restored + $amount, 2);
            $left = round($left - $amount, 2);
        }

        return $restored;
    }

    /**
     * Issue a voucher. `code` is optional — an unprinted voucher gets a generated
     * one, retried on the (vanishingly unlikely) collision rather than failing
     * the sale in front of the customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(array $data, ?int $userId = null): GiftVoucher
    {
        $faceValue = round((float) $data['face_value'], 2);

        if ($faceValue <= 0) {
            throw new RuntimeException('A gift voucher needs a value on it.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $data['code'] ?? GiftVoucher::generateCode();

            if (! $this->findByCode((string) $code)) {
                return GiftVoucher::create([
                    'code' => $code,
                    'customer_id' => $data['customer_id'] ?? null,
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'recipient_phone' => $data['recipient_phone'] ?? null,
                    'face_value' => $faceValue,
                    'balance' => $faceValue,
                    'issued_on' => $data['issued_on'] ?? now()->toDateString(),
                    'expires_on' => $data['expires_on'] ?? null,
                    'status' => 'active',
                    'issued_invoice_id' => $data['issued_invoice_id'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);
            }

            // A code the operator typed is theirs to fix; only generated ones
            // are worth retrying.
            if (isset($data['code'])) {
                throw new RuntimeException("Voucher code \"{$data['code']}\" is already in use.");
            }
        }

        throw new RuntimeException('Could not allocate a unique voucher code. Try again.');
    }

    /**
     * Write one signed movement and recompute the balance from the ledger.
     *
     * Under a row lock and inside a transaction, because the whole point of the
     * balance is that two tills cannot spend it at once.
     */
    private function move(
        GiftVoucher $voucher,
        float $amount,
        ?Invoice $invoice,
        ?SalesOrder $salesOrder,
        string $reference,
        ?int $userId,
    ): GiftVoucherRedemption {
        return DB::transaction(function () use ($voucher, $amount, $invoice, $salesOrder, $reference, $userId) {
            /** @var GiftVoucher $locked */
            $locked = GiftVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($amount > 0 && $amount > (float) $locked->balance + 0.005) {
                throw new RuntimeException(
                    "Voucher {$locked->code} only has ".number_format((float) $locked->balance, 2).' left on it.',
                );
            }

            $redemption = GiftVoucherRedemption::create([
                'gift_voucher_id' => $locked->id,
                'amount' => $amount,
                'invoice_id' => $invoice?->id,
                'sales_order_id' => $salesOrder?->id,
                'redeemed_at' => now(),
                'reference' => $reference,
                'created_by' => $userId,
            ]);

            $spent = (float) GiftVoucherRedemption::where('gift_voucher_id', $locked->id)->sum('amount');

            // Clamped both ways: the ledger is the truth, but a rounding drift
            // must never leave a voucher worth more than it was sold for.
            $locked->balance = max(0.0, min((float) $locked->face_value, round((float) $locked->face_value - $spent, 2)));
            // Saved before the status pass, which returns early on a cancelled
            // voucher — a reversal onto one must still bank the balance.
            $locked->save();
            $locked->refreshStatus();

            return $redemption;
        });
    }
}
