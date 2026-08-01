<?php

declare(strict_types=1);

namespace App\Modules\Industry\Distribution\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * One place that answers "may we supply this party, and for how much?".
 *
 * Before this, three screens each had their own opinion: the POS counter checked
 * the limit against the outstanding, the sales-order confirm checked the limit
 * against the outstanding plus the order, and nothing checked anything at
 * despatch. A wholesaler whose whole business is credit cannot have three rules,
 * so all of them now route through here.
 *
 * The vocabulary:
 *
 *  - outstanding — opening balance plus every unpaid, uncredited rupee on
 *    invoices. Money already billed.
 *  - pipeline — orders confirmed but not yet invoiced. Money about to be billed,
 *    against stock already reserved for the party.
 *  - exposure — outstanding + pipeline + whatever is being asked for now.
 *
 * Whether pipeline counts towards the limit is a policy question with two right
 * answers: a distributor releasing a lorry a day wants it counted, a shop
 * invoicing at the counter does not (the order and the invoice are the same
 * moment, so counting both would double it). Hence `credit_include_pipeline`,
 * off by default — every existing workspace keeps exactly the behaviour it had.
 *
 * Three settings, all defaulting to today's behaviour:
 *
 *  - credit_enforcement       block | warn | off   (default block)
 *  - credit_overdue_block_days  0 = never block on age (default 0)
 *  - credit_include_pipeline    false (default)
 *
 * A manual supply hold is not covered by `credit_enforcement`, and that is on
 * purpose: the mode governs the automatic arithmetic, while a hold is a person
 * saying "not this party, not today". Turning enforcement off must not quietly
 * undo their instruction — the release action does that, visibly.
 */
class CreditControlService
{
    public const MODE_BLOCK = 'block';
    public const MODE_WARN = 'warn';
    public const MODE_OFF = 'off';

    public const MODES = [self::MODE_BLOCK, self::MODE_WARN, self::MODE_OFF];

    /**
     * Fraction of the limit at which a party is flagged 'watch' rather than 'ok'.
     * Public because the Credit Control list applies the same threshold inside
     * SQL, and two copies of this number would eventually differ.
     */
    public const WATCH_THRESHOLD = 0.85;

    /** How the automatic limit and age checks behave. */
    public function mode(): string
    {
        $mode = (string) Setting::get('credit_enforcement', self::MODE_BLOCK);

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_BLOCK;
    }

    /** Days past due after which supply stops regardless of limit. 0 = off. */
    public function overdueBlockDays(): int
    {
        return max(0, (int) Setting::get('credit_overdue_block_days', '0'));
    }

    /** Do confirmed-but-uninvoiced orders count against the limit? */
    public function includesPipeline(): bool
    {
        return filter_var(Setting::get('credit_include_pipeline', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /** The whole policy, for the settings panel on the Credit Control screen. */
    public function policy(): array
    {
        return [
            'mode' => $this->mode(),
            'overdue_block_days' => $this->overdueBlockDays(),
            'include_pipeline' => $this->includesPipeline(),
            // Shown as the fallback next to each party's own terms.
            'default_terms_days' => $this->defaultTermsDays(),
        ];
    }

    /** Workspace-wide credit period, used when a party has no terms of its own. */
    public function defaultTermsDays(): int
    {
        return max(0, (int) Setting::get('sales_payment_term_days', '30'));
    }

    /** This party's credit period: their own terms, else the workspace default. */
    public function termsDays(Customer $customer): int
    {
        $own = (int) ($customer->payment_terms_days ?? 0);

        return $own > 0 ? $own : $this->defaultTermsDays();
    }

    /**
     * Everything the screens show for one party, plus the reasons supply would
     * be refused. `$additional` is the value of the document being considered
     * (an order about to be confirmed, a despatch about to leave); pass 0 to see
     * the party's standing position.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Customer $customer, float $additional = 0): array
    {
        $limit = (float) $customer->credit_limit;
        $outstanding = $customer->outstandingBalance();
        $pipeline = $this->pipeline($customer);
        $overdue = $this->overdue($customer);

        // What the limit is actually measured against — see the class docblock
        // on why pipeline is opt-in.
        $counted = $outstanding + ($this->includesPipeline() ? $pipeline : 0.0) + $additional;
        $blockDays = $this->overdueBlockDays();

        $reasons = [];
        if ($customer->credit_hold) {
            $reasons[] = $customer->credit_hold_reason
                ? 'Supply is on hold: '.$customer->credit_hold_reason
                : 'Supply is on hold.';
        }
        if ($blockDays > 0 && $overdue['oldest_days'] > $blockDays) {
            $reasons[] = sprintf(
                '%s is overdue by %d days, past the %d-day limit.',
                $this->rupees($overdue['amount']),
                $overdue['oldest_days'],
                $blockDays,
            );
        }
        if ($limit > 0 && $counted > $limit) {
            $reasons[] = sprintf(
                'Credit limit exceeded: %s against a %s limit.',
                $this->rupees($counted),
                $this->rupees($limit),
            );
        }

        return [
            'customer_id' => $customer->id,
            'customer' => $customer->name,
            'credit_limit' => round($limit, 2),
            'payment_terms_days' => $this->termsDays($customer),
            'has_own_terms' => (int) ($customer->payment_terms_days ?? 0) > 0,
            'outstanding' => $outstanding,
            'pipeline' => $pipeline,
            'additional' => round($additional, 2),
            'exposure' => round($outstanding + $pipeline + $additional, 2),
            // What the limit was compared against, which differs from `exposure`
            // whenever pipeline is excluded. Reported so a refusal can be
            // explained without guessing at the policy.
            'counted_exposure' => round($counted, 2),
            // Unlimited is null, not a large number: 0 means "no limit set" on
            // the customer master and must not read as "no credit".
            'available' => $limit > 0 ? round($limit - $counted, 2) : null,
            'overdue' => $overdue['amount'],
            'overdue_days' => $overdue['oldest_days'],
            'on_hold' => (bool) $customer->credit_hold,
            'hold_reason' => $customer->credit_hold_reason,
            'hold_at' => $customer->credit_hold_at,
            'status' => $status = $this->statusFor(
                (bool) $customer->credit_hold, $limit, $counted, $overdue['amount'], $overdue['oldest_days'],
            ),
            'reasons' => $reasons,
            // What would actually happen if this document were pushed through.
            'blocked' => $this->blocksStatus($status),
        ];
    }

    /**
     * The party's standing, from figures already in hand. Takes primitives
     * rather than a Customer so the Credit Control list — which aggregates every
     * party in one query — can label its rows with the same rule the individual
     * checks use, instead of re-deriving it per row.
     */
    public function statusFor(bool $onHold, float $limit, float $countedExposure, float $overdueAmount, int $overdueDays): string
    {
        $blockDays = $this->overdueBlockDays();

        return match (true) {
            $onHold => 'hold',
            $limit > 0 && $countedExposure > $limit => 'over_limit',
            $blockDays > 0 && $overdueDays > $blockDays => 'overdue_block',
            $overdueAmount > 0 => 'overdue',
            $limit > 0 && $countedExposure >= $limit * self::WATCH_THRESHOLD => 'watch',
            default => 'ok',
        };
    }

    /**
     * Would supply actually be refused for a party in this status? A hold always
     * refuses; the arithmetic statuses only refuse in 'block' mode, so a 'warn'
     * workspace sees the same red row without the door being shut.
     */
    public function blocksStatus(string $status): bool
    {
        if ($status === 'hold') {
            return true;
        }

        return $this->mode() === self::MODE_BLOCK
            && in_array($status, ['over_limit', 'overdue_block'], true);
    }

    /**
     * Why supply must be refused, or null when it may go ahead. This is the
     * single gate: POS billing, order confirmation, B2B approval and despatch
     * all call it, so a party held on Monday is held everywhere on Monday.
     */
    public function blockReason(Customer $customer, float $additional = 0): ?string
    {
        return $this->blockReasonFrom($customer, $this->snapshot($customer, $additional)['reasons']);
    }

    /**
     * Ageing of one party's open invoices, in days past due, plus the invoices
     * themselves for the drill-down.
     *
     * @return array<string, mixed>
     */
    public function ageing(Customer $customer, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();
        $buckets = self::emptyBuckets();
        $rows = [];

        foreach ($this->openInvoices($customer) as $invoice) {
            $balance = $this->balanceOf($invoice);
            if ($balance <= 0) {
                continue;
            }

            /**
             * Due date, falling back to the invoice date for rows raised before
             * due dates existed. Ageing an old invoice from its invoice date is
             * the harsher reading, which is the right way round for a figure
             * someone is about to chase.
             */
            $due = $invoice->due_date ?? $invoice->invoice_date;
            $daysPastDue = $due ? (int) $due->copy()->startOfDay()->diffInDays($asOf, false) : 0;
            $bucket = self::bucketFor($daysPastDue);

            $buckets[$bucket] = round($buckets[$bucket] + $balance, 2);
            $rows[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $due?->toDateString(),
                'days_past_due' => max(0, $daysPastDue),
                'total' => round((float) $invoice->total, 2),
                'balance' => $balance,
                'bucket' => $bucket,
            ];
        }

        // Oldest first: a collection call starts at the top of this list.
        usort($rows, static fn (array $a, array $b) => $b['days_past_due'] <=> $a['days_past_due']);

        return [
            'as_of' => $asOf->toDateString(),
            'buckets' => $buckets,
            // Carried separately from the buckets because an opening balance has
            // no invoice and therefore no age — folding it into 'not_due' would
            // understate the ageing, and into '90+' would invent a fact.
            'opening_balance' => round((float) $customer->opening_balance, 2),
            'invoice_total' => round(array_sum($buckets), 2),
            'total_outstanding' => round(array_sum($buckets) + (float) $customer->opening_balance, 2),
            'invoices' => $rows,
        ];
    }

    /** Confirmed orders not yet invoiced — stock committed but not billed. */
    public function pipeline(Customer $customer): float
    {
        return round((float) SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['confirmed', 'backordered'])
            ->sum('total'), 2);
    }

    /**
     * Past-due amount and the age of the oldest past-due invoice.
     *
     * @return array{amount: float, oldest_days: int}
     */
    public function overdue(Customer $customer, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->startOfDay();
        $amount = 0.0;
        $oldest = 0;

        foreach ($this->openInvoices($customer) as $invoice) {
            $balance = $this->balanceOf($invoice);
            if ($balance <= 0) {
                continue;
            }

            $due = $invoice->due_date ?? $invoice->invoice_date;
            $days = $due ? (int) $due->copy()->startOfDay()->diffInDays($asOf, false) : 0;
            if ($days <= 0) {
                continue;
            }

            $amount += $balance;
            $oldest = max($oldest, $days);
        }

        return ['amount' => round($amount, 2), 'oldest_days' => $oldest];
    }

    /*
    |--------------------------------------------------------------------------
    | Bucket vocabulary
    |--------------------------------------------------------------------------
    |
    | Days *past due*, not days since billing — which is why this differs from
    | the payables report's ageing. A distributor selling on 30-day terms has
    | most of the ledger sitting legitimately unpaid at any moment; ageing from
    | the invoice date would put all of it in "0-30" and hide the party who is
    | genuinely three weeks late on a 7-day account.
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

    /** Bucket keys → column headings, shared by the screen and the CSV/PDF export. */
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
     * Which reason, if any, actually stops the despatch. Hold always does; the
     * arithmetic reasons obey `credit_enforcement`.
     *
     * @param  list<string>  $reasons
     */
    private function blockReasonFrom(Customer $customer, array $reasons): ?string
    {
        if ($customer->credit_hold) {
            return $reasons[0] ?? 'Supply is on hold.';
        }

        if ($this->mode() !== self::MODE_BLOCK || $reasons === []) {
            return null;
        }

        return $reasons[0];
    }

    /** @return \Illuminate\Support\Collection<int, Invoice> */
    private function openInvoices(Customer $customer)
    {
        return Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->get(['id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount', 'credited_amount']);
    }

    private function balanceOf(Invoice $invoice): float
    {
        return round(
            (float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credited_amount,
            2,
        );
    }

    /** ₹ with thousands separators, for messages a shopkeeper reads. */
    private function rupees(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }
}
