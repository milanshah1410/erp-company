<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BrokerSettlement;
use App\Models\EmbroideryPayment;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\TailorPayment;
use App\Models\Voucher;
use Illuminate\Support\Collection;

/**
 * Builds the derived account books (Cash Book, Bank Book, Journal, Day Book) by
 * unioning the manual vouchers with the transactional money-movement tables.
 * Nothing here writes; every book is a read model.
 *
 * A "movement" is the normalized unit every book is built from:
 *   [date, book(cash|bank|journal), direction(in|out), source, type,
 *    reference, party, mode, amount, account_id]
 */
class AccountingBookService
{
    /** Map a payment mode to the book it belongs in. */
    private function bookFor(?string $mode): string
    {
        return match ($mode) {
            'cash' => 'cash',
            'bank', 'upi', 'card', 'cheque' => 'bank',
            default => 'journal', // credit, adjustment, other, null
        };
    }

    // ---------------------------------------------------------------------
    // Public books
    // ---------------------------------------------------------------------

    /** Cash Book: all cash-mode movements with a running balance. */
    public function cashBook(?string $from, ?string $to): array
    {
        return $this->singleBook('cash', $from, $to, $this->openingBalanceFor('cash', null));
    }

    /**
     * Bank Book: all bank-mode movements with a running balance. When
     * $bankAccountId is given, only voucher movements tied to that account are
     * shown (legacy transactions carry no account id); otherwise every
     * bank-mode movement is shown together.
     */
    public function bankBook(?string $from, ?string $to, ?int $bankAccountId): array
    {
        return $this->singleBook('bank', $from, $to, $this->openingBalanceFor('bank', $bankAccountId), $bankAccountId);
    }

    /**
     * Petty Cash Book: cash movements restricted to accounts flagged as
     * petty-cash floats. Only voucher movements carry an account id, so
     * transactional cash flows (which are not tied to a specific account) are
     * excluded — petty cash is expected to be operated through vouchers.
     */
    public function pettyCashBook(?string $from, ?string $to): array
    {
        $pettyIds = BankAccount::query()
            ->where('type', 'cash')
            ->where('is_petty_cash', true)
            ->pluck('id')
            ->all();

        if (empty($pettyIds)) {
            return [
                'data' => [],
                'summary' => ['opening_balance' => 0.0, 'total_in' => 0.0, 'total_out' => 0.0, 'closing_balance' => 0.0],
            ];
        }

        $opening = (float) BankAccount::query()->whereIn('id', $pettyIds)->sum('opening_balance');

        return $this->singleBook('cash', $from, $to, $opening, $pettyIds);
    }

    /** Day Book: every movement (cash + bank + journal) in the window, chronological. */
    public function dayBook(?string $from, ?string $to): array
    {
        $rows = $this->movements($to)
            ->filter(fn ($m) => $this->inWindow($m['date'], $from, $to))
            ->sortBy([['date', 'asc'], ['type', 'asc']])
            ->values();

        return [
            'data' => $rows->map(fn ($m) => [
                'date' => $m['date'],
                'book' => $m['book'],
                'type' => $m['type'],
                'source' => $m['source'],
                'reference' => $m['reference'],
                'party' => $m['party'],
                'mode' => $m['mode'],
                'direction' => $m['direction'],
                'amount' => round($m['amount'], 2),
            ])->all(),
            'summary' => [
                'total_in' => round($rows->where('direction', 'in')->sum('amount'), 2),
                'total_out' => round($rows->where('direction', 'out')->sum('amount'), 2),
                'count' => $rows->count(),
            ],
        ];
    }

    /** Journal: non-cash entries — journal vouchers plus adjustment-mode transactions. */
    public function journal(?string $from, ?string $to): array
    {
        $rows = $this->movements($to)
            ->filter(fn ($m) => $m['book'] === 'journal')
            ->filter(fn ($m) => $this->inWindow($m['date'], $from, $to))
            ->sortBy([['date', 'asc'], ['type', 'asc']])
            ->values();

        $data = $rows->map(fn ($m) => [
            'date' => $m['date'],
            'type' => $m['type'],
            'reference' => $m['reference'],
            'debit_ledger' => $m['debit_ledger'] ?? ($m['direction'] === 'out' ? ($m['party'] ?? $m['source']) : null),
            'credit_ledger' => $m['credit_ledger'] ?? ($m['direction'] === 'in' ? ($m['party'] ?? $m['source']) : null),
            'narration' => $m['narration'] ?? null,
            'amount' => round($m['amount'], 2),
        ]);

        return [
            'data' => $data->all(),
            'summary' => [
                'total' => round($rows->sum('amount'), 2),
                'count' => $rows->count(),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Shared cash/bank book builder with carried-forward opening balance.
     * $accountId may be a single account id or an array of ids (petty cash).
     */
    private function singleBook(string $book, ?string $from, ?string $to, float $baseOpening, int|array|null $accountId = null): array
    {
        $accountIds = $accountId === null ? null : (array) $accountId;

        $entries = $this->movements($to)
            ->filter(fn ($m) => $m['book'] === $book)
            ->when($accountIds !== null, fn (Collection $c) => $c->filter(fn ($m) => in_array($m['account_id'], $accountIds, true)))
            ->sortBy([['date', 'asc'], ['type', 'asc']])
            ->values();

        // Carry forward: opening = base + net of everything strictly before $from.
        $opening = $baseOpening;
        if ($from) {
            foreach ($entries as $m) {
                if ($m['date'] !== null && $m['date'] < $from) {
                    $opening += $m['direction'] === 'in' ? $m['amount'] : -$m['amount'];
                }
            }
        }

        $running = $opening;
        $totalIn = 0.0;
        $totalOut = 0.0;
        $data = [];
        foreach ($entries as $m) {
            if (! $this->inWindow($m['date'], $from, $to)) {
                continue;
            }
            $in = $m['direction'] === 'in' ? $m['amount'] : 0.0;
            $out = $m['direction'] === 'out' ? $m['amount'] : 0.0;
            $running += $in - $out;
            $totalIn += $in;
            $totalOut += $out;
            $data[] = [
                'date' => $m['date'],
                'type' => $m['type'],
                'source' => $m['source'],
                'reference' => $m['reference'],
                'party' => $m['party'],
                'mode' => $m['mode'],
                'debit' => round($in, 2),  // money in
                'credit' => round($out, 2), // money out
                'balance' => round($running, 2),
            ];
        }

        return [
            'data' => $data,
            'summary' => [
                'opening_balance' => round($opening, 2),
                'total_in' => round($totalIn, 2),
                'total_out' => round($totalOut, 2),
                'closing_balance' => round($running, 2),
            ],
        ];
    }

    /** Sum of opening balances for a book (optionally a single account). */
    private function openingBalanceFor(string $type, ?int $accountId): float
    {
        $q = BankAccount::query()->where('type', $type);
        if ($accountId !== null) {
            $q->where('id', $accountId);
        }

        return (float) $q->sum('opening_balance');
    }

    private function inWindow(?string $date, ?string $from, ?string $to): bool
    {
        if ($date === null) {
            return false;
        }
        if ($from && $date < $from) {
            return false;
        }
        if ($to && $date > $to) {
            return false;
        }

        return true;
    }

    /**
     * Build every normalized movement with date <= $to (the upper bound keeps
     * the working set bounded; the lower bound is handled per-book so opening
     * balances can carry forward).
     */
    private function movements(?string $to): Collection
    {
        $movements = collect();
        $ub = fn ($q, string $col) => $to ? $q->whereDate($col, '<=', $to) : $q;

        // 1. Customer receipts (money in).
        $ub(Payment::query()->with(['invoice:id,invoice_number,customer_id', 'invoice.customer:id,name']), 'payment_date')
            ->get()->each(function ($p) use ($movements) {
                $movements->push($this->normalize(
                    $p->payment_date, $this->bookFor($p->mode), 'in', 'Customer Receipt', 'receipt',
                    $p->invoice?->invoice_number, $p->invoice?->customer?->name, $p->mode, (float) $p->amount,
                ));
            });

        // 2. Supplier payments (money out).
        $ub(SupplierPayment::query()->with(['purchaseInvoice:id,invoice_number,supplier_id', 'purchaseInvoice.supplier:id,name']), 'payment_date')
            ->get()->each(function ($p) use ($movements) {
                $movements->push($this->normalize(
                    $p->payment_date, $this->bookFor($p->mode), 'out', 'Supplier Payment', 'payment',
                    $p->purchaseInvoice?->invoice_number, $p->purchaseInvoice?->supplier?->name, $p->mode, (float) $p->amount,
                ));
            });

        // 3. Broker settlements (money out).
        $ub(BrokerSettlement::query()->with('broker:id,name'), 'settlement_date')
            ->get()->each(function ($s) use ($movements) {
                $movements->push($this->normalize(
                    $s->settlement_date, $this->bookFor($s->mode), 'out', 'Broker Settlement', 'payment',
                    $s->settlement_number, $s->broker?->name, $s->mode, (float) ($s->net_paid ?? $s->amount),
                ));
            });

        // 4. Tailor payments (money out).
        $ub(TailorPayment::query()->with('tailor:id,name'), 'payment_date')
            ->get()->each(function ($p) use ($movements) {
                $movements->push($this->normalize(
                    $p->payment_date, $this->bookFor($p->mode), 'out', 'Tailor Payment', 'payment',
                    $p->payment_number, $p->tailor?->name, $p->mode, (float) $p->amount,
                ));
            });

        // 5. Embroidery payments (money out).
        $ub(EmbroideryPayment::query()->with('vendor:id,name'), 'payment_date')
            ->get()->each(function ($p) use ($movements) {
                $movements->push($this->normalize(
                    $p->payment_date, $this->bookFor($p->mode), 'out', 'Embroidery Payment', 'payment',
                    $p->payment_number, $p->vendor?->name, $p->mode, (float) $p->amount,
                ));
            });

        // 6. Paid, approved expenses (money out).
        $ub(Expense::query()->with('category:id,name')->where('status', 'approved')->whereNotNull('paid_at'), 'expense_date')
            ->get()->each(function ($e) use ($movements) {
                $movements->push($this->normalize(
                    $e->expense_date, $this->bookFor($e->payment_method), 'out', 'Expense', 'payment',
                    $e->expense_number, $e->payee ?: $e->category?->name, $e->payment_method, (float) $e->amount,
                ));
            });

        // 7. Manual vouchers (posted only).
        $ub(Voucher::query()->where('status', 'posted'), 'voucher_date')
            ->get()->each(function ($v) use ($movements) {
                $this->pushVoucher($movements, $v);
            });

        return $movements;
    }

    /** Expand a voucher into one or two movements. */
    private function pushVoucher(Collection $movements, Voucher $v): void
    {
        $date = $v->voucher_date;
        $party = $v->party_name;
        $amount = (float) $v->amount;

        if ($v->voucher_type === 'receipt') {
            $movements->push($this->normalize(
                $date, $this->bookFor($v->mode), 'in', 'Receipt Voucher', 'receipt',
                $v->voucher_number, $party, $v->mode, $amount, $v->bank_account_id, $v->narration,
            ));
        } elseif ($v->voucher_type === 'payment') {
            $movements->push($this->normalize(
                $date, $this->bookFor($v->mode), 'out', 'Payment Voucher', 'payment',
                $v->voucher_number, $party, $v->mode, $amount, $v->bank_account_id, $v->narration,
            ));
        } elseif ($v->voucher_type === 'contra') {
            // Money leaves the source account and enters the destination account.
            $source = BankAccount::find($v->counter_account_id);
            $dest = BankAccount::find($v->bank_account_id);
            if ($source) {
                $movements->push($this->normalize(
                    $date, $source->type === 'cash' ? 'cash' : 'bank', 'out', 'Contra', 'contra',
                    $v->voucher_number, $dest?->name, $v->mode, $amount, $source->id, $v->narration,
                ));
            }
            if ($dest) {
                $movements->push($this->normalize(
                    $date, $dest->type === 'cash' ? 'cash' : 'bank', 'in', 'Contra', 'contra',
                    $v->voucher_number, $source?->name, $v->mode, $amount, $dest->id, $v->narration,
                ));
            }
        } else { // journal
            $m = $this->normalize(
                $date, 'journal', 'out', 'Journal Voucher', 'journal',
                $v->voucher_number, $party, $v->mode, $amount, null, $v->narration,
            );
            $m['debit_ledger'] = $v->debit_ledger;
            $m['credit_ledger'] = $v->credit_ledger;
            $movements->push($m);
        }
    }

    /** @return array<string,mixed> */
    private function normalize(
        $date, string $book, string $direction, string $source, string $type,
        ?string $reference, ?string $party, ?string $mode, float $amount,
        ?int $accountId = null, ?string $narration = null,
    ): array {
        return [
            'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date ? (string) $date : null),
            'book' => $book,
            'direction' => $direction,
            'source' => $source,
            'type' => $type,
            'reference' => $reference,
            'party' => $party,
            'mode' => $mode,
            'amount' => $amount,
            'account_id' => $accountId,
            'narration' => $narration,
        ];
    }
}
