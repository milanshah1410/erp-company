<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Modules\Industry\FoodBeverage\Models\CustomerMembership;
use App\Modules\Industry\FoodBeverage\Models\MembershipPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Selling and honouring a paid membership.
 *
 * A membership is bought, so selling one is a sale: it raises a SalesOrder +
 * Invoice on the 'membership' channel, through the same spine POS, B2B,
 * e-commerce, the restaurant and cake orders all bill through. Nothing about
 * membership money is re-implemented here — what is implemented is the card: who
 * holds one, until when, and what it entitles them to.
 *
 * The card bills as an `is_service` product. A membership has nothing behind it on
 * a shelf, and flagging it that way is what keeps every stock path from looking
 * for one — the same trick the service module's labour line and the restaurant's
 * packaging charge use.
 */
class MembershipService
{
    /** SKU of the catalogue product memberships are billed under. */
    public const PRODUCT_SKU = 'MEMBERSHIP';

    /**
     * Sell a card.
     *
     * Idempotent in the way that matters: a customer who already holds a live card
     * cannot be sold a second one, because two active cards would make "what is
     * this member's discount?" ambiguous at the till. Renewing is `renew()`, which
     * extends from the existing expiry rather than issuing a parallel card.
     */
    public function sell(
        Customer $customer,
        MembershipPlan $plan,
        ?string $startsOn = null,
        ?float $price = null,
        bool $raiseInvoice = true,
        ?int $userId = null,
        ?string $notes = null,
    ): CustomerMembership {
        if ($this->activeFor((int) $customer->id)) {
            throw new RuntimeException("{$customer->name} already holds an active membership.");
        }

        $start = $startsOn ? \Carbon\Carbon::parse($startsOn) : now();
        $charged = $price !== null ? round($price, 2) : (float) $plan->price;

        return DB::transaction(function () use ($customer, $plan, $start, $charged, $raiseInvoice, $userId, $notes) {
            $membership = CustomerMembership::create([
                'customer_id' => $customer->id,
                'membership_plan_id' => $plan->id,
                'member_number' => $this->nextMemberNumber(),
                'starts_on' => $start->toDateString(),
                // Inclusive of the start day: a 365-day card sold today runs to
                // this date next year, which is what the customer was told.
                'ends_on' => $start->copy()->addDays(max(1, (int) $plan->duration_days) - 1)->toDateString(),
                'status' => 'active',
                'price_paid' => $charged,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            /**
             * A comped card raises no invoice. A shop will give one to a regular,
             * and a membership that cannot be given away is one staff will record
             * as a standing discount instead — losing the expiry date, which is
             * the only part of this the system is really keeping.
             */
            if ($raiseInvoice && $charged > 0) {
                $this->bill($membership, $plan, $charged, $userId);
            }

            return $membership;
        });
    }

    /**
     * Extend an existing card.
     *
     * Extends from the later of today and the current expiry, so renewing early
     * does not cost the member the days they have already paid for, and renewing
     * late does not back-date the new term into a period they had no card.
     */
    public function renew(
        CustomerMembership $membership,
        ?float $price = null,
        bool $raiseInvoice = true,
        ?int $userId = null,
    ): CustomerMembership {
        $plan = $membership->plan;

        if (! $plan) {
            throw new RuntimeException("Membership {$membership->member_number} has no plan to renew onto.");
        }

        $charged = $price !== null ? round($price, 2) : (float) $plan->price;

        return DB::transaction(function () use ($membership, $plan, $charged, $raiseInvoice, $userId) {
            $from = $membership->ends_on->isFuture() ? $membership->ends_on->copy() : now()->startOfDay();

            $membership->forceFill([
                'ends_on' => $from->addDays(max(1, (int) $plan->duration_days))->toDateString(),
                'status' => 'active',
                'price_paid' => $charged,
            ])->save();

            if ($raiseInvoice && $charged > 0) {
                $this->bill($membership, $plan, $charged, $userId);
            }

            return $membership->refresh();
        });
    }

    /**
     * Stop a card entitling, immediately.
     *
     * The row stays — a cancelled membership is history a shop needs when the
     * holder comes back to argue — and the money is not touched. A refund is a
     * credit note through Sales, raised by whoever is allowed to give money back.
     */
    public function cancel(CustomerMembership $membership, ?string $reason = null): CustomerMembership
    {
        $membership->forceFill([
            'status' => 'cancelled',
            'notes' => trim((string) $membership->notes."\nCancelled: ".($reason ?: 'no reason given')),
        ])->save();

        return $membership;
    }

    /** The card a customer currently holds, or null. */
    public function activeFor(?int $customerId): ?CustomerMembership
    {
        if (! $customerId) {
            return null;
        }

        return CustomerMembership::query()
            ->where('customer_id', $customerId)
            ->entitling()
            ->with('plan')
            ->orderByDesc('ends_on')
            ->first();
    }

    /**
     * The standing discount percentage a customer is entitled to right now.
     *
     * Zero when they hold no card, which lets a caller apply it unconditionally
     * rather than branching on membership everywhere.
     */
    public function discountPercentFor(?int $customerId): float
    {
        $membership = $this->activeFor($customerId);

        return $membership ? (float) ($membership->plan?->discount_percent ?? 0) : 0.0;
    }

    /**
     * Mark lapsed cards expired.
     *
     * Housekeeping for the reports, not the thing entitlement depends on —
     * CustomerMembership::scopeEntitling() tests the dates itself, so a card stops
     * working at midnight whether or not this has run. Returns the number flipped.
     */
    public function expireLapsed(): int
    {
        return CustomerMembership::query()
            ->where('status', 'active')
            ->whereDate('ends_on', '<', now()->toDateString())
            ->update(['status' => 'expired']);
    }

    /**
     * Raise the sale for a card.
     *
     * One line, no tax by default: a membership fee's GST treatment varies by
     * state and by what the shop is actually selling, so the product carries the
     * rate and this does not invent one.
     */
    private function bill(
        CustomerMembership $membership,
        MembershipPlan $plan,
        float $amount,
        ?int $userId,
    ): void {
        $product = $this->membershipProduct();

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $membership->customer_id,
            'warehouse_id' => $this->fallbackWarehouseId(),
            'status' => 'delivered',
            'channel' => 'membership',
            'order_date' => now()->toDateString(),
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
            'notes' => "Membership {$membership->member_number} — {$plan->name}",
            'created_by' => $userId,
            'delivered_at' => now(),
        ]);

        $salesOrder->items()->create([
            'product_id' => $product->id,
            'variant_id' => null,
            'quantity' => 1,
            'unit_price' => $amount,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'line_total' => $amount,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $membership->customer_id,
            'invoice_date' => now()->toDateString(),
            'total' => $amount,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $membership->forceFill([
            'sales_order_id' => $salesOrder->id,
            'invoice_id' => $invoice->id,
        ])->save();
    }

    /** The catalogue product memberships bill under, created on first sale. */
    private function membershipProduct(): Product
    {
        return Product::firstOrCreate(
            ['sku' => self::PRODUCT_SKU],
            [
                'name' => 'Membership Fee',
                'description' => 'Paid membership / club card fee.',
                'is_service' => true,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => 0,
                'tax_percent' => 0,
            ],
        );
    }

    /**
     * Next card number: MEM-000001, and so on.
     *
     * Derived from the row count rather than a settings-backed series because a
     * member number is not an accounting document — it is a string on a plastic
     * card, and the unique index is what actually guarantees it.
     */
    private function nextMemberNumber(): string
    {
        $last = CustomerMembership::query()->orderByDesc('id')->value('id') ?? 0;

        return 'MEM-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    private function fallbackWarehouseId(): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException('Create a warehouse before selling a membership.');
        }

        return (int) $id;
    }
}
