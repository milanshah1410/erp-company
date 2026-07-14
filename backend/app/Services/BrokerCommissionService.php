<?php

namespace App\Services;

use App\Models\Broker;
use App\Models\BrokerCommission;
use App\Models\BrokerCommissionRate;
use App\Models\BrokerSettlement;
use App\Models\BrokerWalletTransaction;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Broker commission accounting. One place for the money math behind commission
 * accrual, sales-return reversals, TDS-aware settlements (payouts) and the
 * broker wallet.
 *
 * An invoice can carry MANY brokers (invoice_brokers), each with an optional
 * rate override and a share_percent of the deal. When no pivot rows exist the
 * invoice's single broker_id is used. Commission is booked on the sale VALUE
 * excl. tax (net taxable, after discounts). The rate is resolved per invoice
 * line with precedence: invoice-broker override -> per-product rate ->
 * per-customer rate -> broker default. A broker default fixed commission is
 * added once per broker (scaled by their share).
 *
 * Every accrual/reversal/settlement also posts a matching broker wallet entry
 * so broker.wallet_balance always mirrors what the company owes the broker.
 */
class BrokerCommissionService
{
    /**
     * Book commission for a freshly created invoice across every broker on it.
     * No-op when the invoice has no broker. Idempotent per broker: an existing
     * accrual for an invoice+broker is skipped, so re-runs can't double-book.
     * Returns the first accrual created (or null) — mostly for the single-broker
     * callers; use BrokerCommission queries to read the full multi-broker set.
     */
    public function accrueForInvoice(Invoice $invoice): ?BrokerCommission
    {
        $first = null;

        foreach ($this->resolveInvoiceBrokers($invoice) as $entry) {
            $row = $this->accrueEntry($invoice, $entry);
            $first ??= $row;
        }

        return $first;
    }

    /**
     * Copy a sales order's brokers onto its invoice at delivery. Falls back to
     * nothing when the order used only the single broker_id (accrual then reads
     * invoice.broker_id directly).
     */
    public function copyBrokersToInvoice(SalesOrder $salesOrder, Invoice $invoice): void
    {
        foreach ($salesOrder->brokers()->get() as $sob) {
            $invoice->brokers()->create([
                'broker_id' => $sob->broker_id,
                'commission_percent' => $sob->commission_percent,
                'fixed_commission' => $sob->fixed_commission,
                'share_percent' => $sob->share_percent,
            ]);
        }
    }

    /**
     * Reverse commission proportionally across every broker on the invoice when
     * a sales return is approved. Each accrual is reversed by the same fraction
     * of value returned (returned taxable / original taxable), booked negative.
     * Idempotent per return+broker.
     */
    public function reverseForReturn(SalesReturn $return): ?BrokerCommission
    {
        $returnBase = $return->taxableValue();
        if ($returnBase <= 0) {
            return null;
        }

        $accruals = BrokerCommission::query()
            ->where('invoice_id', $return->invoice_id)
            ->where('type', 'accrual')
            ->orderBy('id')
            ->get();

        $first = null;

        foreach ($accruals as $accrual) {
            $already = BrokerCommission::query()
                ->where('sales_return_id', $return->id)
                ->where('broker_id', $accrual->broker_id)
                ->where('type', 'reversal')
                ->exists();
            if ($already) {
                continue;
            }

            $originalBase = (float) $accrual->base_amount;
            if ($originalBase <= 0) {
                continue;
            }
            $fraction = min(1.0, $returnBase / $originalBase);
            $reversal = round((float) $accrual->commission_amount * $fraction, 2);
            if ($reversal <= 0) {
                continue;
            }

            $row = BrokerCommission::create([
                'broker_id' => $accrual->broker_id,
                'invoice_id' => $return->invoice_id,
                'sales_return_id' => $return->id,
                'type' => 'reversal',
                'base_amount' => round($returnBase, 2),
                'commission_percent' => $accrual->commission_percent,
                'fixed_commission' => 0,
                'commission_amount' => -$reversal,
                'settled_amount' => 0,
                'status' => 'pending',
                'notes' => "Reversal for credit note {$return->credit_note_number}",
            ]);

            $broker = Broker::find($accrual->broker_id);
            if ($broker) {
                $this->recordWallet($broker, 'reversal', -$reversal, $row, "Reversal for credit note {$return->credit_note_number}");
            }

            $first ??= $row;
        }

        return $first;
    }

    /**
     * Record a payout to a broker: withhold TDS (default from the broker),
     * FIFO-allocate the GROSS across the broker's oldest unsettled accruals,
     * post a wallet debit, and return the settlement. The gross clears the
     * broker's commission liability; net_paid is the cash actually handed over.
     */
    public function settle(Broker $broker, array $data): BrokerSettlement
    {
        $gross = round((float) $data['amount'], 2);
        $tdsPercent = array_key_exists('tds_percent', $data) && $data['tds_percent'] !== null
            ? (float) $data['tds_percent']
            : (float) $broker->tds_percent;
        $tdsAmount = round($gross * $tdsPercent / 100, 2);
        $netPaid = round($gross - $tdsAmount, 2);

        $settlement = BrokerSettlement::create([
            'broker_id' => $broker->id,
            'settlement_number' => BrokerSettlement::nextNumber(),
            'amount' => $gross,
            'tds_percent' => $tdsPercent,
            'tds_amount' => $tdsAmount,
            'net_paid' => $netPaid,
            'mode' => $data['mode'] ?? 'cash',
            'settlement_date' => $data['settlement_date'] ?? now()->toDateString(),
            'period_month' => $data['period_month'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $remaining = $gross;

        $accruals = BrokerCommission::query()
            ->where('broker_id', $broker->id)
            ->where('type', 'accrual')
            ->where('status', '!=', 'paid')
            ->orderBy('id')
            ->get();

        foreach ($accruals as $accrual) {
            if ($remaining <= 0.005) {
                break;
            }
            $outstanding = $accrual->outstanding();
            if ($outstanding <= 0) {
                continue;
            }
            $apply = min($remaining, $outstanding);

            $settlement->allocations()->create([
                'broker_commission_id' => $accrual->id,
                'amount' => round($apply, 2),
            ]);

            $accrual->settled_amount = round((float) $accrual->settled_amount + $apply, 2);
            $accrual->refreshStatus();

            $remaining = round($remaining - $apply, 2);
        }

        $this->recordWallet($broker, 'settlement', -$gross, $settlement, "Settlement {$settlement->settlement_number}");

        return $settlement;
    }

    /**
     * Record a manual wallet movement (top-up, advance drawn, or adjustment)
     * and return the transaction. Signed amount: positive credits the broker.
     */
    public function recordWalletManual(Broker $broker, string $type, float $amount, ?string $notes, ?int $createdBy): BrokerWalletTransaction
    {
        return DB::transaction(fn () => $this->recordWallet($broker, $type, $amount, null, $notes, $createdBy));
    }

    /**
     * Post a wallet entry and roll the broker's running balance forward. amount
     * is signed (positive = owed more). No-op (returns null) for a zero amount.
     */
    protected function recordWallet(Broker $broker, string $type, float $amount, ?Model $reference = null, ?string $notes = null, ?int $createdBy = null): ?BrokerWalletTransaction
    {
        if (abs($amount) < 0.005) {
            return null;
        }

        // Read the authoritative balance from the DB: the passed instance may be
        // stale (e.g. an accrual updated the balance through a different instance
        // earlier in the same request).
        $current = (float) (Broker::whereKey($broker->id)->value('wallet_balance') ?? $broker->wallet_balance);
        $balanceAfter = round($current + $amount, 2);

        $txn = $broker->walletTransactions()->create([
            'type' => $type,
            'amount' => round($amount, 2),
            'balance_after' => $balanceAfter,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
            'created_by' => $createdBy,
        ]);

        // Direct update, not $broker->save(): the passed instance may be stale,
        // so dirty-tracking on it can't be trusted to persist the new balance.
        Broker::whereKey($broker->id)->update(['wallet_balance' => $balanceAfter]);
        $broker->wallet_balance = $balanceAfter;

        return $txn;
    }

    /**
     * The list of brokers to accrue for this invoice. Prefers the invoice_brokers
     * pivot; falls back to the single broker_id. Each entry carries the resolved
     * Broker plus per-invoice overrides and the share_percent.
     *
     * @return array<int, array{broker: Broker, percent: ?float, fixed: ?float, share: float}>
     */
    private function resolveInvoiceBrokers(Invoice $invoice): array
    {
        $pivots = $invoice->brokers()->get();

        if ($pivots->isNotEmpty()) {
            $brokers = Broker::whereIn('id', $pivots->pluck('broker_id'))->get()->keyBy('id');

            return $pivots
                ->filter(fn ($p) => $brokers->has($p->broker_id))
                ->map(fn ($p) => [
                    'broker' => $brokers->get($p->broker_id),
                    'percent' => $p->commission_percent !== null ? (float) $p->commission_percent : null,
                    'fixed' => $p->fixed_commission !== null ? (float) $p->fixed_commission : null,
                    'share' => (float) ($p->share_percent ?? 100),
                ])->values()->all();
        }

        if ($invoice->broker_id) {
            $broker = Broker::find($invoice->broker_id);
            if ($broker) {
                return [['broker' => $broker, 'percent' => null, 'fixed' => null, 'share' => 100.0]];
            }
        }

        return [];
    }

    /** @param array{broker: Broker, percent: ?float, fixed: ?float, share: float} $entry */
    private function accrueEntry(Invoice $invoice, array $entry): ?BrokerCommission
    {
        $broker = $entry['broker'];

        $exists = BrokerCommission::query()
            ->where('invoice_id', $invoice->id)
            ->where('broker_id', $broker->id)
            ->where('type', 'accrual')
            ->exists();
        if ($exists) {
            return null;
        }

        $share = $entry['share'] > 0 ? $entry['share'] : 100.0;
        $fixed = $entry['fixed'] ?? (float) $broker->fixed_commission;

        [$base, $percentPart] = $this->computeBaseAndPercentPart($invoice, $entry);

        $grossPercentPart = round($percentPart * $share / 100, 2);
        $grossFixed = round($fixed * $share / 100, 2);
        $amount = round($grossPercentPart + $grossFixed, 2);
        $effectivePercent = $base > 0 ? round($grossPercentPart / $base * 100, 2) : 0.0;

        $commission = BrokerCommission::create([
            'broker_id' => $broker->id,
            'invoice_id' => $invoice->id,
            'type' => 'accrual',
            'base_amount' => round($base, 2),
            'commission_percent' => $effectivePercent,
            'fixed_commission' => $grossFixed,
            'commission_amount' => $amount,
            'settled_amount' => 0,
            'status' => 'pending',
        ]);

        $this->recordWallet($broker, 'commission', $amount, $commission, "Commission on invoice {$invoice->invoice_number}");

        return $commission;
    }

    /**
     * Base (net taxable the broker earned on) and the percentage part of the
     * commission, resolved per invoice line. Falls back to the sales order's
     * whole taxable value when the order has no line items.
     *
     * @param  array{broker: Broker, percent: ?float, fixed: ?float, share: float}  $entry
     * @return array{0: float, 1: float}
     */
    private function computeBaseAndPercentPart(Invoice $invoice, array $entry): array
    {
        $broker = $entry['broker'];
        $customerId = $invoice->customer_id;
        $rates = $broker->rates()->get();

        $items = $invoice->salesOrder?->items()->get() ?? new Collection;

        if ($items->isNotEmpty()) {
            $base = 0.0;
            $percentPart = 0.0;
            foreach ($items as $line) {
                $lineTaxable = round((float) $line->line_total - (float) $line->tax_amount, 2);
                if ($lineTaxable <= 0) {
                    continue;
                }
                $pct = $this->resolvePercent($entry, $broker, $rates, $customerId, $line->product_id);
                $base += $lineTaxable;
                $percentPart += $pct / 100 * $lineTaxable;
            }

            return [round($base, 2), $percentPart];
        }

        $base = (float) ($invoice->salesOrder?->taxableValue() ?? 0);
        $pct = $this->resolvePercent($entry, $broker, $rates, $customerId, null);

        return [round($base, 2), $pct / 100 * $base];
    }

    /**
     * Resolve the commission percent for one line: invoice-broker override wins,
     * then a per-product rate, then a per-customer rate, then the broker default.
     *
     * @param  array{broker: Broker, percent: ?float, fixed: ?float, share: float}  $entry
     * @param  Collection<int, BrokerCommissionRate>  $rates
     */
    private function resolvePercent(array $entry, Broker $broker, Collection $rates, ?int $customerId, ?int $productId): float
    {
        if ($entry['percent'] !== null) {
            return $entry['percent'];
        }

        if ($productId) {
            $productRate = $rates->first(fn ($r) => $r->scope === 'product' && (int) $r->product_id === (int) $productId);
            if ($productRate) {
                return (float) $productRate->commission_percent;
            }
        }

        if ($customerId) {
            $customerRate = $rates->first(fn ($r) => $r->scope === 'customer' && (int) $r->customer_id === (int) $customerId);
            if ($customerRate) {
                return (float) $customerRate->commission_percent;
            }
        }

        return (float) $broker->commission_percent;
    }
}
