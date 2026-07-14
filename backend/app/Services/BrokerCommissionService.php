<?php

namespace App\Services;

use App\Models\Broker;
use App\Models\BrokerCommission;
use App\Models\BrokerSettlement;
use App\Models\Invoice;
use App\Models\SalesReturn;

/**
 * Broker commission accounting. One place for the money math behind the
 * "One Invoice = One Broker" accrual, sales-return reversals, and broker
 * settlements (payouts). Commission is booked on the sale VALUE excl. tax
 * (the sales order's net taxable value, after discounts), snapshotting the
 * broker's rate at accrual time.
 */
class BrokerCommissionService
{
    /**
     * Book commission for a freshly created invoice. No-op when the invoice
     * has no broker. Idempotent: skips if an accrual for this invoice+broker
     * already exists, so re-runs (or double delivery) can't double-book.
     */
    public function accrueForInvoice(Invoice $invoice): ?BrokerCommission
    {
        if (! $invoice->broker_id) {
            return null;
        }

        $exists = BrokerCommission::query()
            ->where('invoice_id', $invoice->id)
            ->where('broker_id', $invoice->broker_id)
            ->where('type', 'accrual')
            ->exists();
        if ($exists) {
            return null;
        }

        $broker = Broker::find($invoice->broker_id);
        if (! $broker) {
            return null;
        }

        $base = (float) ($invoice->salesOrder?->taxableValue() ?? 0);
        $percent = (float) $broker->commission_percent;
        $fixed = (float) $broker->fixed_commission;
        $amount = round($fixed + $percent / 100 * $base, 2);

        return BrokerCommission::create([
            'broker_id' => $broker->id,
            'invoice_id' => $invoice->id,
            'type' => 'accrual',
            'base_amount' => round($base, 2),
            'commission_percent' => $percent,
            'fixed_commission' => $fixed,
            'commission_amount' => $amount,
            'settled_amount' => 0,
            'status' => 'pending',
        ]);
    }

    /**
     * Reverse commission proportionally when a sales return is approved.
     * Books a negative row against the original accrual: the percentage part
     * scales with the returned value, the fixed part is prorated by the share
     * of the original sale value being returned.
     */
    public function reverseForReturn(SalesReturn $return): ?BrokerCommission
    {
        $accrual = BrokerCommission::query()
            ->where('invoice_id', $return->invoice_id)
            ->where('type', 'accrual')
            ->orderBy('id')
            ->first();
        if (! $accrual) {
            return null;
        }

        $returnBase = $return->taxableValue();
        if ($returnBase <= 0) {
            return null;
        }

        $originalBase = (float) $accrual->base_amount;
        $percentPart = (float) $accrual->commission_percent / 100 * $returnBase;
        $fixedPart = $originalBase > 0
            ? (float) $accrual->fixed_commission * ($returnBase / $originalBase)
            : 0.0;
        $reversal = round($percentPart + $fixedPart, 2);
        if ($reversal <= 0) {
            return null;
        }

        return BrokerCommission::create([
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
    }

    /**
     * Record a payout to a broker and FIFO-allocate it across the broker's
     * oldest not-fully-settled accruals, updating each row's settled amount
     * and status. Returns the created settlement.
     */
    public function settle(Broker $broker, array $data): BrokerSettlement
    {
        $settlement = BrokerSettlement::create([
            'broker_id' => $broker->id,
            'settlement_number' => BrokerSettlement::nextNumber(),
            'amount' => round((float) $data['amount'], 2),
            'mode' => $data['mode'] ?? 'cash',
            'settlement_date' => $data['settlement_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $remaining = (float) $settlement->amount;

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

        return $settlement;
    }
}
