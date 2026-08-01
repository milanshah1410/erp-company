<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PlatformCoupon;
use App\Models\PlatformPack;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantPack;
use App\Support\Modules;
use App\Support\PackEntitlement;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grants and revokes marketplace packs, and projects the resulting entitlement
 * into the tenant's settings (Volume 11 §Module Marketplace).
 *
 * The important design point: a pack grant does **not** introduce a new
 * enforcement path. It writes the `pack_modules` setting, which
 * {@see \App\Support\EnabledModules} unions into what the plan allows — so the nav,
 * the role matrix and the API all gate on it through the machinery that was
 * already there, and a revoked pack closes its endpoints the same moment it
 * disappears from the menu.
 *
 * Granting also switches the pack's modules **on** in `enabled_modules`. A customer
 * who just bought the Restaurant pack should find Restaurant in their menu, not a
 * checkbox they have to go and discover.
 */
class PackEntitlementService
{
    public function __construct(
        private PlatformTaxCalculator $tax,
        private CouponService $coupons,
        private InvoiceNumberService $numbers,
    ) {}

    /**
     * Grant a pack to a workspace.
     *
     * @param  array{
     *     source?: string, price?: float|null, starts_at?: string|Carbon|null,
     *     ends_at?: string|Carbon|null, notes?: string|null, granted_by?: int|null,
     *     coupon_code?: string|null, bill?: bool
     * }  $options
     */
    public function grant(Tenant $tenant, PlatformPack $pack, array $options = []): TenantPack
    {
        if (! $pack->is_active) {
            throw ValidationException::withMessages(['pack' => ['That pack is not available.']]);
        }

        if ($this->liveGrant($tenant->id, $pack->id)) {
            throw ValidationException::withMessages(['pack' => [$pack->name.' is already active for this workspace.']]);
        }

        $source = $options['source'] ?? 'purchased';
        $price = array_key_exists('price', $options) && $options['price'] !== null
            ? round((float) $options['price'], 2)
            : (float) $pack->price;

        // A comped pack is free by definition — an operator handing one over should
        // not also be able to bill for it by leaving a price in the payload.
        if ($source === 'comped') {
            $price = 0.0;
        }

        $starts = isset($options['starts_at']) ? Carbon::parse($options['starts_at']) : Carbon::now();
        $ends = match (true) {
            isset($options['ends_at']) && $options['ends_at'] !== null => Carbon::parse($options['ends_at']),
            // A one-time purchase is perpetual; a recurring pack runs until
            // cancelled, so neither sets an end date. An explicit date is how an
            // operator grants a trial or a fixed-term concession.
            default => null,
        };

        $connection = config('saas.central_connection', 'central');

        $grant = DB::connection($connection)->transaction(function () use ($tenant, $pack, $source, $price, $starts, $ends, $options) {
            $grant = TenantPack::create([
                'tenant_id' => $tenant->id,
                'platform_pack_id' => $pack->id,
                'status' => 'active',
                'source' => $source,
                'price' => $price,
                'currency' => $pack->currency,
                'starts_at' => $starts->toDateString(),
                'ends_at' => $ends?->toDateString(),
                'granted_by' => $options['granted_by'] ?? null,
                'notes' => $options['notes'] ?? null,
            ]);

            // Billing is opt-out so the default (an operator selling a pack) puts
            // the money on the books; `bill => false` is for a concession that was
            // settled some other way.
            if ($price > 0 && ($options['bill'] ?? true)) {
                $invoice = $this->issuePackInvoice($tenant, $pack, $grant, $price, $options['coupon_code'] ?? null);
                $grant->update(['subscription_invoice_id' => $invoice?->id]);
            }

            return $grant;
        });

        // Only *this* pack's modules are switched on. The tenant's entitlement may
        // cover more (anything its plan bundles), but those were never asked for —
        // switching them on as a side effect of a purchase would put screens in a
        // workspace's menu that nobody chose.
        $this->syncTenant($tenant->id, $pack->moduleKeys());

        return $grant->fresh(['pack']);
    }

    /**
     * Revoke a live grant. The entitlement goes away immediately; any invoice
     * already raised for it stays on the books (refund it if that is the intent —
     * revoking is not a credit note).
     */
    public function revoke(TenantPack $grant, ?string $reason = null): TenantPack
    {
        if ($grant->status !== 'active') {
            return $grant;
        }

        $grant->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'notes' => trim((string) $grant->notes.($reason ? "\nRevoked: {$reason}" : '')) ?: null,
        ]);

        $this->syncTenant((int) $grant->tenant_id);

        return $grant->fresh(['pack']);
    }

    /**
     * Recompute the tenant's pack entitlement and write it into their settings.
     *
     * `pack_modules` is the projection EnabledModules reads — the full set the
     * tenant's packs entitle, granted and plan-bundled alike.
     *
     * `enabled_modules` is the tenant's *own* selection, and this only nudges it at
     * the edges: modules that just stopped being entitled are switched off, and
     * `$switchOn` (the modules of the pack being granted right now) are switched on.
     * Nothing else is touched — in particular, the rest of the entitlement is left
     * alone, because a workspace that never asked for the modules its plan happens
     * to bundle should not find them in its menu after buying something unrelated.
     *
     * @param  list<string>  $switchOn  Modules to enable as well, if the tenant is gated.
     */
    public function syncTenant(int $tenantId, array $switchOn = []): void
    {
        PackEntitlement::flush($tenantId);
        $current = PackEntitlement::modulesFor($tenantId);

        app(TenantContext::class)->runWith($tenantId, function () use ($tenantId, $current, $switchOn): void {
            $previous = $this->storedList('pack_modules') ?? [];
            $stored = $this->storedList('enabled_modules');

            DB::transaction(function () use ($tenantId, $current, $previous, $stored, $switchOn): void {
                Setting::updateOrCreate(
                    ['tenant_id' => $tenantId, 'key' => 'pack_modules'],
                    ['value' => json_encode($current)],
                );

                // A tenant with no stored selection is not gated at all; adding one
                // here would start gating them as a side effect of a pack purchase.
                if ($stored === null) {
                    return;
                }

                // No longer entitled: what the projection used to carry and no
                // longer does. Anything still entitled stays exactly as the tenant
                // had it, switched on or off.
                $dropped = array_diff($previous, $current);
                // Never enable something the entitlement does not actually cover.
                $enable = array_intersect($switchOn, $current);

                $next = Modules::sanitize(array_values(array_unique(
                    array_merge(array_diff($stored, $dropped), $enable),
                )));

                Setting::updateOrCreate(
                    ['tenant_id' => $tenantId, 'key' => 'enabled_modules'],
                    ['value' => json_encode($next)],
                );
            });

            // Invalidates the cached nav catalogue and the revoked-group set.
            TenantCache::bump('menu_items');
        });
    }

    /** The tenant's live grant of one pack, if it holds it. */
    public function liveGrant(int $tenantId, int $packId): ?TenantPack
    {
        return TenantPack::query()
            ->where('tenant_id', $tenantId)
            ->where('platform_pack_id', $packId)
            ->live()
            ->first();
    }

    /**
     * The marketplace as one workspace sees it: every active pack, flagged with
     * whether it is already held, bundled with the plan, or purchasable.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(Tenant $tenant): array
    {
        $plan = Subscription::with('plan')->where('tenant_id', $tenant->id)->latest('id')->first()?->plan;

        $grants = TenantPack::query()
            ->where('tenant_id', $tenant->id)
            ->live()
            ->get()
            ->keyBy('platform_pack_id');

        return PlatformPack::query()
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get()
            ->map(function (PlatformPack $pack) use ($grants, $plan): array {
                $grant = $grants->get($pack->id);
                $bundled = $pack->isBundledWith($plan);

                return [
                    'id' => $pack->id,
                    'slug' => $pack->slug,
                    'name' => $pack->name,
                    'type' => $pack->type,
                    'tagline' => $pack->tagline,
                    'icon' => $pack->icon,
                    'price' => (float) $pack->price,
                    'currency' => $pack->currency,
                    'billing_cycle' => $pack->billing_cycle,
                    'modules' => $pack->moduleKeys(),
                    'module_labels' => array_map(
                        fn (string $key) => Modules::CATALOGUE[$key]['label'] ?? $key,
                        $pack->moduleKeys(),
                    ),
                    'limit_bumps' => $pack->limitBumps(),
                    'held' => $grant !== null,
                    'grant_id' => $grant?->id,
                    'grant_source' => $grant?->source,
                    'bundled_with_plan' => $bundled,
                    // Nothing to sell when the plan already includes it.
                    'purchasable' => $grant === null && ! $bundled,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Raise the invoice for a purchased pack. Filed against the tenant's
     * subscription with `type = 'pack'`, so pack revenue lands in the same ledger
     * as plan revenue instead of a parallel one nothing reports on.
     */
    private function issuePackInvoice(
        Tenant $tenant,
        PlatformPack $pack,
        TenantPack $grant,
        float $price,
        ?string $couponCode,
    ): ?SubscriptionInvoice {
        $subscription = Subscription::where('tenant_id', $tenant->id)->latest('id')->first();

        if (! $subscription) {
            // No subscription row means no ledger to file against. The grant still
            // stands; the operator collects for it outside the panel.
            return null;
        }

        $coupon = null;
        $discount = 0.0;

        if (trim((string) $couponCode) !== '') {
            $coupon = $this->coupons->validate($couponCode, [
                'tenant_id' => $tenant->id,
                'amount' => $price,
                'purchase_type' => 'pack',
            ]);
            $discount = $coupon->discountOn($price);
        }

        $breakdown = $this->tax->breakdown($price, $discount, $pack->currency, $tenant);
        $dueDays = (int) config('saas.billing.renewal.due_days', 7);

        $invoice = SubscriptionInvoice::create([
            'number' => $this->numbers->next(),
            'subscription_id' => $subscription->id,
            'type' => 'pack',
            'status' => 'created',
            'tenant_pack_id' => $grant->id,
            'due_date' => Carbon::now()->addDays($dueDays)->toDateString(),
            'period_start' => $grant->starts_at?->toDateString(),
            'period_end' => $grant->ends_at?->toDateString(),
            'notes' => $pack->name.' ('.ucfirst($pack->type).' pack)',
            'amount' => $breakdown['amount'],
            'currency' => $breakdown['currency'],
            'subtotal' => $breakdown['subtotal'],
            'discount_amount' => $breakdown['discount_amount'],
            'taxable_amount' => $breakdown['taxable_amount'],
            'tax_rate' => $breakdown['tax_rate'],
            'cgst_amount' => $breakdown['cgst_amount'],
            'sgst_amount' => $breakdown['sgst_amount'],
            'igst_amount' => $breakdown['igst_amount'],
            'tax_amount' => $breakdown['tax_amount'],
            'place_of_supply' => $breakdown['place_of_supply'],
            'seller_state_code' => $breakdown['seller_state_code'],
            'is_interstate' => $breakdown['is_interstate'],
            'customer_gstin' => $breakdown['customer_gstin'],
            'customer_legal_name' => $breakdown['customer_legal_name'],
            'sac_code' => $breakdown['sac_code'],
            'platform_coupon_id' => $coupon?->id,
            'coupon_code' => $coupon?->code,
        ]);

        if ($coupon instanceof PlatformCoupon && $discount > 0) {
            $this->coupons->redeem($coupon, (int) $tenant->id, $discount, $subscription, $invoice);
        }

        return $invoice;
    }

    /**
     * A tenant setting read as a list, or null when unset. Read raw for the same
     * reason TenantAccessController does: the accessor's per-tenant cache can be
     * warm from before this request.
     *
     * @return list<string>|null
     */
    private function storedList(string $key): ?array
    {
        $raw = Setting::where('key', $key)->value('value');
        if (empty($raw)) {
            return null;
        }

        $value = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($value) ? array_values($value) : null;
    }
}
