<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CommissionEarning;
use App\Models\CommissionRule;
use App\Models\Employee;
use App\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shop-staff sales commission engine (retail base §8b).
 *
 * Distinct from SalesmanIncentiveService, which pays a *field* rep for hitting a
 * monthly target and derives everything live. This one is line-by-line and
 * stored: a counter hand earns 1% on this shirt and 3% on that brand, and what
 * they were told they earned must not change afterwards.
 *
 * Three decisions shape the whole file:
 *
 *  1. **One rule per line, not all of them.** Rules are ranked by `priority` and
 *     the first match wins. Summing them would mean a shop that says "1% on
 *     everything, 3% on Brand X" pays 4% on Brand X, which is not what anybody
 *     means by that sentence.
 *
 *  2. **Returns are netted, not clawed back.** A line's commissionable quantity
 *     is what was sold minus what came back. Nothing is deducted from a paid
 *     earning; the recomputed figure simply excludes goods that are back on the
 *     shelf. Re-running a closed month therefore lowers an *open* earning and
 *     leaves a paid one alone — see writeEarning().
 *
 *  3. **Re-running is safe.** Every earning is keyed on
 *     (line, rule, employee), so generating a month twice produces the same rows
 *     rather than double pay. That is the property that lets a shop generate
 *     daily if it wants to.
 */
class SalesCommissionService
{
    /**
     * Generate (or refresh) earnings for a month.
     *
     * @return array{generated:int, updated:int, skipped_no_employee:int, total:float}
     */
    public function generate(int $year, int $month, ?int $employeeId = null): array
    {
        [$start, $end] = $this->monthBounds($year, $month);

        $rules = $this->activeRules();
        if ($rules->isEmpty()) {
            return ['generated' => 0, 'updated' => 0, 'skipped_no_employee' => 0, 'total' => 0.0];
        }

        // user_id → employee, so each line resolves its seller without a query.
        $employeesByUser = Employee::query()
            ->where('is_active', true)
            ->get(['id', 'user_id', 'department_id'])
            ->keyBy('user_id');

        $orders = SalesOrder::query()
            ->with(['items.product:id,category_id,brand_id', 'invoice:id'])
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('created_by')
            ->get();

        $costs = $this->lineCosts($orders->pluck('id')->all());
        $returned = $this->returnedQuantities($orders->pluck('id')->all());

        $generated = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0.0;

        DB::transaction(function () use (
            $orders, $rules, $employeesByUser, $costs, $returned, $year, $month, $employeeId,
            &$generated, &$updated, &$skipped, &$total
        ) {
            foreach ($orders as $order) {
                $employee = $employeesByUser->get($order->created_by);
                if (! $employee) {
                    // The bill was rung up by a user with no employee record —
                    // an owner, or an admin account. Counted and reported rather
                    // than silently dropped, because "why is my total low?" is
                    // otherwise unanswerable.
                    $skipped++;

                    continue;
                }
                if ($employeeId !== null && $employee->id !== $employeeId) {
                    continue;
                }

                $orderDate = $order->order_date->toDateString();
                $invoiceTotal = (float) $order->total;

                foreach ($order->items as $item) {
                    $soldQty = (float) $item->quantity;
                    $backQty = (float) ($returned[$order->id][$item->product_id] ?? 0);
                    $netQty = max($soldQty - $backQty, 0);

                    // Everything came back. Any existing open earning is written
                    // down to zero rather than deleted, so the row still explains
                    // why nothing was earned.
                    $unitValue = $soldQty > 0
                        ? ((float) $item->line_total - (float) $item->tax_amount) / $soldQty
                        : 0.0;
                    $lineValue = round($unitValue * $netQty, 2);

                    $unitCost = $soldQty > 0 && isset($costs[$order->id][$item->product_id])
                        ? (float) $costs[$order->id][$item->product_id] / $soldQty
                        : null;
                    $costAmount = $unitCost === null ? null : round($unitCost * $netQty, 2);
                    $margin = $costAmount === null ? null : round($lineValue - $costAmount, 2);

                    $rule = $this->winningRule(
                        $rules, $employee->id, $employee->department_id,
                        $item->product_id, $item->product?->category_id, $item->product?->brand_id,
                        $orderDate, $invoiceTotal, $lineValue,
                    );
                    if (! $rule) {
                        continue;
                    }

                    $amount = $rule->commissionFor($netQty, $lineValue, $margin);

                    $result = $this->writeEarning($employee, $rule, $order, $item, [
                        'earned_on' => $orderDate,
                        'period_year' => $year,
                        'period_month' => $month,
                        'quantity' => $netQty,
                        'sale_amount' => $lineValue,
                        'cost_amount' => $costAmount,
                        'margin_amount' => $margin,
                        'basis' => $rule->basis,
                        'rate' => $rule->rate,
                        'commission_amount' => $amount,
                    ]);

                    if ($result === 'created') {
                        $generated++;
                        $total += $amount;
                    } elseif ($result === 'updated') {
                        $updated++;
                        $total += $amount;
                    }
                }
            }
        });

        return [
            'generated' => $generated,
            'updated' => $updated,
            'skipped_no_employee' => $skipped,
            'total' => round($total, 2),
        ];
    }

    /**
     * Write one earning.
     *
     * A paid earning is never touched: the money has left, and silently editing
     * it would make a payslip disagree with the ledger behind it. A shop that
     * genuinely needs to correct a paid figure reverses it, which is an explicit
     * act with a name on it.
     *
     * @return 'created'|'updated'|'settled'
     */
    private function writeEarning(Employee $employee, CommissionRule $rule, SalesOrder $order, $item, array $values): string
    {
        $existing = CommissionEarning::query()
            ->where('sales_item_id', $item->id)
            ->where('commission_rule_id', $rule->id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($existing) {
            if ($existing->isSettled() || $existing->status === 'reversed') {
                return 'settled';
            }
            $existing->update($values);

            return 'updated';
        }

        CommissionEarning::create($values + [
            'employee_id' => $employee->id,
            'commission_rule_id' => $rule->id,
            'sales_order_id' => $order->id,
            'invoice_id' => $order->invoice?->id,
            'sales_item_id' => $item->id,
            'product_id' => $item->product_id,
            'status' => 'pending',
        ]);

        return 'created';
    }

    /**
     * The rule that pays this line: lowest priority number among those that
     * match, first one wins. Rules arrive pre-sorted, so this is a single pass.
     *
     * Both money floors are *matching* conditions here, not deductions applied
     * after a rule has won. That distinction is the whole behaviour of the engine:
     * a rule reading "5% on lines over ₹50,000" must not apply to a ₹1,100 line at
     * all, or — sitting at a high priority — it would win every small line, pay
     * nothing on each, and silently starve the shop-wide 1% rule underneath it.
     * A shop would see a month of zeroes and no reason for them.
     */
    private function winningRule(
        $rules, int $employeeId, ?int $departmentId,
        ?int $productId, ?int $categoryId, ?int $brandId,
        string $date, float $invoiceTotal, float $lineValue,
    ): ?CommissionRule {
        foreach ($rules as $rule) {
            if ($invoiceTotal + 0.005 < (float) $rule->min_invoice_amount) {
                continue;
            }
            if ($lineValue + 0.005 < (float) $rule->min_line_amount) {
                continue;
            }
            if (! $rule->isEffectiveOn($date)) {
                continue;
            }
            if (! $rule->coversSeller($employeeId, $departmentId)) {
                continue;
            }
            if (! $rule->coversProduct($productId, $categoryId, $brandId)) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    private function activeRules()
    {
        return CommissionRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cost of goods per (order, product), from the stock ledger — the same source
     * the profitability report reads, so a margin-based commission and the profit
     * report can never disagree about what something cost.
     *
     * @return array<int, array<int, float>>
     */
    private function lineCosts(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('stock_ledgers')
            ->where('type', 'sale')
            ->where('reference_type', SalesOrder::class)
            ->whereIn('reference_id', $orderIds)
            ->selectRaw('reference_id, product_id, SUM(quantity_out * unit_cost) as cost_total')
            ->groupBy('reference_id', 'product_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->reference_id][(int) $row->product_id] = (float) $row->cost_total;
        }

        return $out;
    }

    /**
     * What came back, per (order, product), from approved sales returns.
     *
     * Matched on the product rather than on a line id because a sales return
     * carries no `sales_item_id` — it is raised against the *invoice*, and the
     * customer brings back "one of these", not "line 4". Good enough in practice:
     * a bill rarely carries the same article on two lines, and when it does the
     * commissionable total is right even if which line absorbed the return is
     * arbitrary.
     *
     * @return array<int, array<int, float>>
     */
    private function returnedQuantities(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->join('invoices', 'invoices.id', '=', 'sales_returns.invoice_id')
            ->whereIn('invoices.sales_order_id', $orderIds)
            ->where('sales_returns.status', 'approved')
            ->selectRaw('invoices.sales_order_id as order_id, sales_return_items.product_id, SUM(sales_return_items.quantity) as qty')
            ->groupBy('invoices.sales_order_id', 'sales_return_items.product_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->order_id][(int) $row->product_id] = (float) $row->qty;
        }

        return $out;
    }

    /** First and last day of the given month. */
    private function monthBounds(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return [$start, $start->endOfMonth()];
    }
}
