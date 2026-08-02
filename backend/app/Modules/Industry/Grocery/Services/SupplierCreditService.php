<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Services;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * The shop's khata, read from the debtor's side.
 *
 * Distribution's CreditControlService answers "may we supply this party?" — the
 * creditor's question, with a limit the workspace itself sets and a supply hold
 * it can impose. This is the mirror, and the mirror is not symmetrical: a kirana
 * does not decide its distributors' credit terms, it lives inside them. So there
 * is no hold here and nothing is ever blocked. What a shopkeeper needs at seven
 * in the morning is a different question with a very concrete answer: **whom must
 * I pay today, and which distributor stops delivering if I don't.**
 *
 * Why this is not the existing Payables report. That report (Purchase → Reports →
 * Payables) totals what is owed per supplier in age buckets and is genuinely the
 * right screen for the accountant. It cannot answer three things a counter needs:
 *
 *  - the **limit** the distributor extends, and how much of it is left. It has
 *    sat on `suppliers.credit_limit` since the beginning and nothing has ever
 *    read it.
 *  - **pipeline** — goods ordered and approved but not yet billed. They are
 *    already against the shop's credit at the distributor's end, so a limit
 *    measured on invoices alone reads clear on the morning a lorry arrives.
 *  - **which bills**, by name and by day. A total in a 31-60 bucket is not a
 *    payment instruction; "Patel Agencies, bill 4412, ₹18,300, nine days late"
 *    is.
 *
 * Ageing is measured in days *past due*, not days since billing — a shop buying
 * on 15-day terms has most of its ledger legitimately unpaid at any moment, and
 * ageing from the bill date would bury the one distributor who is genuinely three
 * weeks late among thirty who are not. This is the same vocabulary the
 * receivables side uses, on purpose: an owner who has learned to read one ageing
 * screen should not have to learn a second.
 */
class SupplierCreditService
{
    /**
     * Fraction of the limit at which a supplier is flagged 'watch' rather than
     * 'ok'. Deliberately the same threshold the receivables side uses.
     */
    public const WATCH_THRESHOLD = 0.85;

    /**
     * Workspace-wide purchase credit period, used when a supplier carries no
     * terms of its own. Mirrors `sales_payment_term_days` on the selling side.
     */
    public function defaultTermsDays(): int
    {
        return max(0, (int) Setting::get('purchase_payment_term_days', '30'));
    }

    /** This supplier's credit period: their own terms, else the workspace default. */
    public function termsDays(Supplier $supplier): int
    {
        $own = (int) ($supplier->payment_terms_days ?? 0);

        return $own > 0 ? $own : $this->defaultTermsDays();
    }

    /**
     * Everything the credit screen shows for one supplier.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Supplier $supplier, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();

        $limit = (float) $supplier->credit_limit;
        $outstanding = $supplier->outstandingBalance();
        $pipeline = $this->pipeline($supplier);
        $exposure = round($outstanding + $pipeline, 2);
        $overdue = $this->overdue($supplier, $asOf);
        $next = $this->nextDue($supplier, $asOf);

        $status = $this->statusFor($limit, $exposure, $overdue['amount'], $overdue['oldest_days']);

        return [
            'supplier_id' => $supplier->id,
            'supplier' => $supplier->name,
            'code' => $supplier->code,
            'phone' => $supplier->phone,
            'credit_limit' => round($limit, 2),
            'payment_terms_days' => $this->termsDays($supplier),
            'has_own_terms' => (int) ($supplier->payment_terms_days ?? 0) > 0,
            'outstanding' => $outstanding,
            'pipeline' => $pipeline,
            'exposure' => $exposure,
            /**
             * Unlimited is null, not a large number: a zero on the master means
             * "no limit recorded" and must not read as "no credit given" — most
             * kirana suppliers never state one.
             */
            'available' => $limit > 0 ? round($limit - $exposure, 2) : null,
            'overdue' => $overdue['amount'],
            'overdue_days' => $overdue['oldest_days'],
            'due_today' => $this->dueWithin($supplier, 0, $asOf),
            'next_due_date' => $next['date'],
            'next_due_amount' => $next['amount'],
            'status' => $status,
            'reasons' => $this->reasons($supplier, $limit, $exposure, $overdue),
        ];
    }

    /**
     * The supplier's standing, from figures already in hand.
     *
     * Takes primitives rather than a Supplier so the list screen — which
     * aggregates every supplier at once — labels its rows with the same rule the
     * detail view uses instead of re-deriving it per row.
     */
    public function statusFor(float $limit, float $exposure, float $overdueAmount, int $overdueDays): string
    {
        return match (true) {
            // Over the limit outranks late: a distributor who has stopped
            // supplying is today's problem, a bill nine days late is this week's.
            $limit > 0 && $exposure > $limit => 'over_limit',
            $overdueDays > 0 || $overdueAmount > 0 => 'overdue',
            $limit > 0 && $exposure >= $limit * self::WATCH_THRESHOLD => 'watch',
            default => 'ok',
        };
    }

    /**
     * Bill-wise ageing of one supplier's open invoices, plus the bills
     * themselves — which is the payment instruction the totals cannot be.
     *
     * @return array<string, mixed>
     */
    public function ageing(Supplier $supplier, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();
        $buckets = self::emptyBuckets();
        $rows = [];

        foreach ($this->openInvoices($supplier) as $invoice) {
            $balance = $this->balanceOf($invoice);

            if ($balance <= 0) {
                continue;
            }

            $due = $this->dueDateOf($invoice, $supplier);
            $daysPastDue = $due ? (int) $due->copy()->startOfDay()->diffInDays($asOf, false) : 0;
            $bucket = self::bucketFor($daysPastDue);

            $buckets[$bucket] = round($buckets[$bucket] + $balance, 2);
            $rows[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $due?->toDateString(),
                // Negative days are "still has this long to run" and are useful
                // on a payment planner, so they are reported rather than floored.
                'days_past_due' => $daysPastDue,
                'total' => round((float) $invoice->total, 2),
                'balance' => $balance,
                'bucket' => $bucket,
            ];
        }

        // Latest first: the oldest bill is the one being paid this morning.
        usort($rows, static fn (array $a, array $b) => $b['days_past_due'] <=> $a['days_past_due']);

        return [
            'as_of' => $asOf->toDateString(),
            'buckets' => $buckets,
            /**
             * Carried separately from the buckets: an opening balance has no bill
             * behind it and therefore no age. Folding it into 'not_due' would
             * understate what is owed, and into '90+' would invent a fact.
             */
            'opening_balance' => round((float) $supplier->opening_balance, 2),
            'invoice_total' => round(array_sum($buckets), 2),
            'total_outstanding' => round(array_sum($buckets) + (float) $supplier->opening_balance, 2),
            'invoices' => $rows,
        ];
    }

    /**
     * Bills falling due within the next N days, across every supplier — the
     * morning's payment list, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentPlan(int $days = 7, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();
        $horizon = $asOf->copy()->addDays(max(0, $days));

        $rows = [];

        $invoices = PurchaseInvoice::query()
            ->with('supplier:id,code,name,phone,payment_terms_days')
            ->where('status', 'approved')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->get(['id', 'supplier_id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount']);

        foreach ($invoices as $invoice) {
            $balance = $this->balanceOf($invoice);

            if ($balance <= 0 || ! $invoice->supplier) {
                continue;
            }

            $due = $this->dueDateOf($invoice, $invoice->supplier);

            // A bill with no date to work from is due now, not never: the harsher
            // reading is the right one on a list somebody is about to pay from.
            $due ??= $asOf;

            if ($due->copy()->startOfDay()->gt($horizon)) {
                continue;
            }

            $daysPastDue = (int) $due->copy()->startOfDay()->diffInDays($asOf, false);

            $rows[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'supplier_id' => $invoice->supplier_id,
                'supplier' => $invoice->supplier->name,
                'code' => $invoice->supplier->code,
                'phone' => $invoice->supplier->phone,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $due->toDateString(),
                'days_past_due' => $daysPastDue,
                'balance' => $balance,
                'bucket' => self::bucketFor($daysPastDue),
            ];
        }

        usort($rows, static fn (array $a, array $b) => $b['days_past_due'] <=> $a['days_past_due']);

        return $rows;
    }

    /**
     * Approved purchase orders not yet billed — goods coming, credit already
     * taken at the distributor's end.
     *
     * `PurchaseOrder` has no invoices relation to lean on, so the exclusion is a
     * keyed subquery. It carries the tenant scope of its own model, so this
     * cannot leak another workspace's orders out of the count.
     */
    public function pipeline(Supplier $supplier): float
    {
        return round((float) PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', ['approved', 'partially_received', 'received'])
            ->whereNotIn('id', PurchaseInvoice::query()
                ->whereNotNull('purchase_order_id')
                ->where('status', '!=', 'cancelled')
                ->select('purchase_order_id'))
            ->sum('total'), 2);
    }

    /**
     * The next bill falling due and what it is worth — the one line a counter
     * actually acts on.
     *
     * "Next" is the earliest *unpaid* due date, which for a shop already behind is
     * a date in the past. That is deliberate: the next thing to pay is the oldest
     * thing unpaid, not the next thing to fall due.
     *
     * @return array{date: string|null, amount: float}
     */
    public function nextDue(Supplier $supplier, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();
        $earliest = null;
        $amount = 0.0;

        foreach ($this->openInvoices($supplier) as $invoice) {
            $balance = $this->balanceOf($invoice);

            if ($balance <= 0) {
                continue;
            }

            $due = ($this->dueDateOf($invoice, $supplier) ?? $asOf)->copy()->startOfDay();

            if ($earliest === null || $due->lt($earliest)) {
                $earliest = $due;
                $amount = $balance;

                continue;
            }

            // Two bills falling on the same day are one payment.
            if ($due->eq($earliest)) {
                $amount += $balance;
            }
        }

        return [
            'date' => $earliest?->toDateString(),
            'amount' => round($amount, 2),
        ];
    }

    /**
     * Past-due amount and the age of the oldest past-due bill.
     *
     * @return array{amount: float, oldest_days: int}
     */
    public function overdue(Supplier $supplier, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();
        $amount = 0.0;
        $oldest = 0;

        foreach ($this->openInvoices($supplier) as $invoice) {
            $balance = $this->balanceOf($invoice);

            if ($balance <= 0) {
                continue;
            }

            $due = $this->dueDateOf($invoice, $supplier);
            $days = $due ? (int) $due->copy()->startOfDay()->diffInDays($asOf, false) : 0;

            if ($days <= 0) {
                continue;
            }

            $amount += $balance;
            $oldest = max($oldest, $days);
        }

        return ['amount' => round($amount, 2), 'oldest_days' => $oldest];
    }

    /** What falls due on or before `today + $days`. */
    public function dueWithin(Supplier $supplier, int $days, ?Carbon $asOf = null): float
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();
        $horizon = $asOf->copy()->addDays(max(0, $days));
        $amount = 0.0;

        foreach ($this->openInvoices($supplier) as $invoice) {
            $balance = $this->balanceOf($invoice);

            if ($balance <= 0) {
                continue;
            }

            $due = $this->dueDateOf($invoice, $supplier) ?? $asOf;

            if ($due->copy()->startOfDay()->lte($horizon)) {
                $amount += $balance;
            }
        }

        return round($amount, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Bucket vocabulary — deliberately the receivables side's, mirrored.
    |--------------------------------------------------------------------------
    */

    /** @return array<string, float> */
    public static function emptyBuckets(): array
    {
        return [
            'not_due' => 0.0,
            'due_0_30' => 0.0,
            'due_31_60' => 0.0,
            'due_61_90' => 0.0,
            'due_90_plus' => 0.0,
        ];
    }

    /** Bucket key for a days-past-due figure (negative = not yet due). */
    public static function bucketFor(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'not_due',
            $daysPastDue <= 30 => 'due_0_30',
            $daysPastDue <= 60 => 'due_31_60',
            $daysPastDue <= 90 => 'due_61_90',
            default => 'due_90_plus',
        };
    }

    /** Bucket keys → column headings, shared by the screen and the CSV export. */
    public static function bucketLabels(): array
    {
        return [
            'not_due' => 'Not Due',
            'due_0_30' => 'Overdue 1-30',
            'due_31_60' => 'Overdue 31-60',
            'due_61_90' => 'Overdue 61-90',
            'due_90_plus' => 'Overdue 90+',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Why this supplier is flagged, in the words a shopkeeper would use.
     *
     * @param  array{amount: float, oldest_days: int}  $overdue
     * @return list<string>
     */
    private function reasons(Supplier $supplier, float $limit, float $exposure, array $overdue): array
    {
        $out = [];

        if ($limit > 0 && $exposure > $limit) {
            $out[] = sprintf(
                '%s outstanding against a %s limit — this distributor may stop supplying.',
                $this->rupees($exposure),
                $this->rupees($limit),
            );
        }

        if ($overdue['oldest_days'] > 0) {
            $out[] = sprintf(
                '%s is overdue, the oldest bill by %d days.',
                $this->rupees($overdue['amount']),
                $overdue['oldest_days'],
            );
        }

        return $out;
    }

    /**
     * A bill's due date: its own, else projected from the supplier's terms, else
     * the bill date.
     *
     * The projection is what makes the terms column on the supplier master mean
     * something at last. Vendor invoices raised before due dates were captured
     * still age from the bill date, which is the harsher reading and the right
     * way round for a figure somebody is about to pay from.
     */
    private function dueDateOf(PurchaseInvoice $invoice, Supplier $supplier): ?Carbon
    {
        if ($invoice->due_date) {
            return $invoice->due_date->copy();
        }

        if (! $invoice->invoice_date) {
            return null;
        }

        return $invoice->invoice_date->copy()->addDays($this->termsDays($supplier));
    }

    /** @return \Illuminate\Support\Collection<int, PurchaseInvoice> */
    private function openInvoices(Supplier $supplier)
    {
        return PurchaseInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'approved')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->get(['id', 'supplier_id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount']);
    }

    private function balanceOf(PurchaseInvoice $invoice): float
    {
        return round((float) $invoice->total - (float) $invoice->paid_amount, 2);
    }

    /** ₹ with thousands separators, for messages a shopkeeper reads. */
    private function rupees(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }
}
