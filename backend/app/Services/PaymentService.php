<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentVoucher;
use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified payment accounting. One place for creating payment vouchers,
 * distributing them across open documents (allocations), and unwinding them on
 * deletion — keeping each target document's paid_amount and status in sync.
 *
 * Partial payment  = an allocation smaller than the target's balance due.
 * Advance payment  = a voucher (or leftover) that stays unallocated; the
 *                    remainder is the party's ledger credit, applied later.
 */
class PaymentService
{
    /**
     * Create a voucher and, when provided, allocate it across documents.
     * Party/allocatable types arrive as API aliases and are resolved here.
     * Runs atomically.
     */
    public function record(array $data): PaymentVoucher
    {
        return DB::transaction(function () use ($data) {
            $partyAlias = $data['party_type'] ?? null;

            $voucher = PaymentVoucher::create([
                'voucher_number' => PaymentVoucher::nextNumber(),
                'direction' => $data['direction'],
                'party_type' => $partyAlias ? (PaymentVoucher::PARTY_TYPES[$partyAlias] ?? null) : null,
                'party_id' => $partyAlias ? ($data['party_id'] ?? null) : null,
                'amount' => round((float) $data['amount'], 2),
                'allocated_amount' => 0,
                'mode' => $data['mode'],
                'reference_no' => $data['reference_no'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_date' => $data['cheque_date'] ?? null,
                'cheque_status' => $data['mode'] === 'cheque' ? ($data['cheque_status'] ?? 'pending') : null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            if (! empty($data['allocations'])) {
                $this->allocate($voucher, $data['allocations']);
            }

            return $voucher;
        });
    }

    /**
     * Apply (more of) a voucher to open documents. Validates that each target's
     * balance is not exceeded and that total allocations never exceed the
     * voucher amount. Bumps each document's paid_amount and refreshes status.
     */
    public function allocate(PaymentVoucher $voucher, array $allocations): PaymentVoucher
    {
        return DB::transaction(function () use ($voucher, $allocations) {
            $added = 0;

            foreach ($allocations as $line) {
                $amount = round((float) $line['amount'], 2);
                if ($amount <= 0) {
                    continue;
                }

                $target = $this->resolveTarget($voucher->direction, $line['allocatable_type'], (int) $line['allocatable_id']);

                if ($amount > $target->balanceDue() + 0.005) {
                    throw ValidationException::withMessages([
                        'allocations' => ["Allocation of {$amount} exceeds the balance due of {$target->balanceDue()} on this {$line['allocatable_type']}."],
                    ]);
                }

                $voucher->allocations()->create([
                    'allocatable_type' => $target->getMorphClass(),
                    'allocatable_id' => $target->getKey(),
                    'amount' => $amount,
                ]);

                $target->paid_amount = round((float) $target->paid_amount + $amount, 2);
                $this->refreshTarget($target);

                $added += $amount;
            }

            $voucher->allocated_amount = round((float) $voucher->allocated_amount + $added, 2);

            if ($voucher->allocated_amount > (float) $voucher->amount + 0.005) {
                throw ValidationException::withMessages([
                    'allocations' => ['Total allocations exceed the payment amount of '.$voucher->amount.'.'],
                ]);
            }

            $voucher->save();

            return $voucher;
        });
    }

    /**
     * Reverse every allocation (crediting the balance back to each document)
     * and delete the voucher. Runs atomically.
     */
    public function delete(PaymentVoucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            foreach ($voucher->allocations as $allocation) {
                $target = $allocation->allocatable;
                if ($target) {
                    $target->paid_amount = round((float) $target->paid_amount - (float) $allocation->amount, 2);
                    $this->refreshTarget($target);
                }
            }

            $voucher->allocations()->delete();
            $voucher->delete();
        });
    }

    /**
     * A party's outstanding advance / on-account credit: the sum of the
     * unallocated portion of its vouchers in the given direction.
     */
    public function partyAdvanceBalance(string $partyAlias, int $partyId, string $direction): float
    {
        $class = PaymentVoucher::PARTY_TYPES[$partyAlias] ?? null;
        if (! $class) {
            return 0.0;
        }

        return (float) PaymentVoucher::query()
            ->where('party_type', $class)
            ->where('party_id', $partyId)
            ->where('direction', $direction)
            ->selectRaw('COALESCE(SUM(amount - allocated_amount), 0) AS bal')
            ->value('bal');
    }

    /** Resolve an allocation target and guard it against the voucher direction. */
    private function resolveTarget(string $direction, string $typeAlias, int $id): Model
    {
        $map = PaymentVoucher::ALLOCATABLE_TYPES;

        if (! isset($map[$typeAlias])) {
            throw ValidationException::withMessages([
                'allocations' => ["Unknown allocation target '{$typeAlias}'."],
            ]);
        }

        // Inbound (customer receipts) settle sales invoices; outbound settles vendor invoices.
        $expected = $direction === 'inbound' ? 'invoice' : 'purchase_invoice';
        if ($typeAlias !== $expected) {
            throw ValidationException::withMessages([
                'allocations' => ["A {$direction} payment cannot be allocated to a {$typeAlias}."],
            ]);
        }

        return $map[$typeAlias]::findOrFail($id);
    }

    /** Recompute the target document's payment status after a balance change. */
    private function refreshTarget(Model $target): void
    {
        if ($target instanceof Invoice) {
            $target->refreshStatus();
        } elseif ($target instanceof PurchaseInvoice) {
            $target->refreshPaymentStatus();
        } else {
            $target->save();
        }
    }
}
