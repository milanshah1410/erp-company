<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChannelIntegration;
use App\Models\PaymentGatewayAccount;
use App\Models\Setting;
use App\Models\WhatsappMessage;

/**
 * Everything the product can connect to, as one catalogue (Volume 11
 * §"Integration Marketplace").
 *
 * The pieces already existed — marketplace connectors in PlatformRegistry,
 * gateways in config/payments.php, MSG91 behind SmsService — but each was only
 * visible from inside the tenant screen that used it. The SaaS owner had no list
 * of what they sell, no way to withhold one from a plan, and no count of who
 * adopted what. This is that list.
 *
 * A catalogue entry is code, not data: shipping a connector is a deploy. What an
 * operator changes at runtime — on sale / off sale, which plans, beta badge —
 * lives in `marketplace_items` and is applied by MarketplaceGate.
 *
 * `status` is deliberately honest about the difference between the two things a
 * marketplace usually blurs:
 *
 *  - 'live'    — a tenant can connect this today; the surface exists.
 *  - 'planned' — listed so the roadmap is visible and an operator can pre-flag
 *                interest, but nothing connects yet. Never enabled by default,
 *                and MarketplaceGate refuses it regardless of the row.
 *
 * `adoption` says how to count tenants that actually connected it, so the panel
 * reports measured uptake rather than a config guess.
 */
final class IntegrationCatalogue
{
    /** Grouping shown as sections on the marketplace screen. */
    public const CATEGORIES = [
        'messaging' => 'Messaging',
        'payments' => 'Payments',
        'marketplace' => 'Marketplaces & Storefronts',
        'logistics' => 'Logistics & Shipping',
        'accounting' => 'Accounting & Finance',
        'network' => 'Open Networks',
    ];

    /**
     * key => metadata.
     *
     * adoption: ['channel'|'gateway'|'setting'|'table'|'none', ...args]
     *   channel  — channel_integrations.platform = key
     *   gateway  — payment_gateway_accounts.gateway = key
     *   setting  — a tenant Setting whose value matches
     *   table    — distinct tenant_id on a model (the integration leaves traces)
     *   none     — nothing to count (planned entries)
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // ---------------------------------------------------------------
            // Messaging
            // ---------------------------------------------------------------
            'whatsapp' => [
                'label' => 'WhatsApp',
                'category' => 'messaging',
                'icon' => 'pi pi-whatsapp',
                'vendor' => 'Meta',
                'status' => 'live',
                'module' => 'crm',
                'description' => 'Send invoices, order updates and campaign messages to customers on WhatsApp.',
                'configured_at' => 'Tenant → CRM → WhatsApp',
                'adoption' => ['table', WhatsappMessage::class],
            ],
            'msg91' => [
                'label' => 'MSG91 SMS',
                'category' => 'messaging',
                'icon' => 'pi pi-send',
                'vendor' => 'MSG91',
                'status' => 'live',
                'module' => null,
                'description' => 'Transactional SMS and login OTPs over the MSG91 gateway.',
                'configured_at' => 'Tenant → Settings → SMS',
                'adoption' => ['setting', 'sms_driver', 'msg91'],
            ],

            // ---------------------------------------------------------------
            // Payments — keys mirror config/payments.php
            // ---------------------------------------------------------------
            'razorpay' => [
                'label' => 'Razorpay',
                'category' => 'payments',
                'icon' => 'pi pi-credit-card',
                'vendor' => 'Razorpay',
                'status' => 'live',
                'module' => null,
                'description' => 'Collect invoice payments by card, UPI, netbanking and wallets; also powers in-app plan upgrades.',
                'configured_at' => 'Tenant → Settings → Payment Gateways',
                'adoption' => ['gateway', 'razorpay'],
            ],
            'cashfree' => [
                'label' => 'Cashfree',
                'category' => 'payments',
                'icon' => 'pi pi-credit-card',
                'vendor' => 'Cashfree Payments',
                'status' => 'live',
                'module' => null,
                'description' => 'Payment links and collection against sales invoices.',
                'configured_at' => 'Tenant → Settings → Payment Gateways',
                'adoption' => ['gateway', 'cashfree'],
            ],
            'phonepe' => [
                'label' => 'PhonePe',
                'category' => 'payments',
                'icon' => 'pi pi-mobile',
                'vendor' => 'PhonePe',
                'status' => 'live',
                'module' => null,
                'description' => 'UPI-first collection, including counter QR at the POS.',
                'configured_at' => 'Tenant → Settings → Payment Gateways',
                'adoption' => ['gateway', 'phonepe'],
            ],
            'paytm' => [
                'label' => 'Paytm',
                'category' => 'payments',
                'icon' => 'pi pi-wallet',
                'vendor' => 'Paytm',
                'status' => 'live',
                'module' => null,
                'description' => 'Wallet and UPI collection with POS QR support.',
                'configured_at' => 'Tenant → Settings → Payment Gateways',
                'adoption' => ['gateway', 'paytm'],
            ],
            'payu' => [
                'label' => 'PayU',
                'category' => 'payments',
                'icon' => 'pi pi-credit-card',
                'vendor' => 'PayU',
                'status' => 'live',
                'module' => null,
                'description' => 'Card and netbanking collection against sales invoices.',
                'configured_at' => 'Tenant → Settings → Payment Gateways',
                'adoption' => ['gateway', 'payu'],
            ],
            'ccavenue' => [
                'label' => 'CCAvenue',
                'category' => 'payments',
                'icon' => 'pi pi-credit-card',
                'vendor' => 'Infibeam Avenues',
                'status' => 'live',
                'module' => null,
                'description' => 'Multi-currency card gateway favoured by export-facing sellers.',
                'configured_at' => 'Tenant → Settings → Payment Gateways',
                'adoption' => ['gateway', 'ccavenue'],
            ],
            'stripe' => [
                'label' => 'Stripe',
                'category' => 'payments',
                'icon' => 'pi pi-credit-card',
                'vendor' => 'Stripe',
                'status' => 'live',
                'module' => null,
                'description' => 'International card collection for overseas customers.',
                'configured_at' => 'Tenant → Settings → Payment Gateways',
                'adoption' => ['gateway', 'stripe'],
            ],

            // ---------------------------------------------------------------
            // Marketplaces & storefronts — keys mirror PlatformRegistry
            // ---------------------------------------------------------------
            'meesho' => [
                'label' => 'Meesho',
                'category' => 'marketplace',
                'icon' => 'pi pi-shopping-bag',
                'vendor' => 'Meesho',
                'status' => 'live',
                'module' => 'ecommerce',
                'description' => 'Pull supplier orders and push inventory to the Meesho supplier panel.',
                'configured_at' => 'Tenant → E-commerce → Integrations',
                'adoption' => ['channel', 'meesho'],
            ],
            'amazon' => [
                'label' => 'Amazon',
                'category' => 'marketplace',
                'icon' => 'pi pi-amazon',
                'vendor' => 'Amazon',
                'status' => 'live',
                'module' => 'ecommerce',
                'description' => 'SP-API order import and inventory sync for Amazon sellers.',
                'configured_at' => 'Tenant → E-commerce → Integrations',
                'adoption' => ['channel', 'amazon'],
            ],
            'flipkart' => [
                'label' => 'Flipkart',
                'category' => 'marketplace',
                'icon' => 'pi pi-shopping-cart',
                'vendor' => 'Flipkart',
                'status' => 'live',
                'module' => 'ecommerce',
                'description' => 'Seller API order import and stock push.',
                'configured_at' => 'Tenant → E-commerce → Integrations',
                'adoption' => ['channel', 'flipkart'],
            ],
            'shopify' => [
                'label' => 'Shopify',
                'category' => 'marketplace',
                'icon' => 'pi pi-shopping-bag',
                'vendor' => 'Shopify',
                'status' => 'live',
                'module' => 'ecommerce',
                'description' => 'Two-way sync of orders, listings and inventory with a Shopify storefront.',
                'configured_at' => 'Tenant → E-commerce → Integrations',
                'adoption' => ['channel', 'shopify'],
            ],
            'woocommerce' => [
                'label' => 'WooCommerce',
                'category' => 'marketplace',
                'icon' => 'pi pi-globe',
                'vendor' => 'Automattic',
                'status' => 'live',
                'module' => 'ecommerce',
                'description' => 'REST sync with a self-hosted WooCommerce store.',
                'configured_at' => 'Tenant → E-commerce → Integrations',
                'adoption' => ['channel', 'woocommerce'],
            ],
            'magento' => [
                'label' => 'Magento',
                'category' => 'marketplace',
                'icon' => 'pi pi-shopping-cart',
                'vendor' => 'Adobe',
                'status' => 'live',
                'module' => 'ecommerce',
                'description' => 'Order and catalogue sync with Magento / Adobe Commerce.',
                'configured_at' => 'Tenant → E-commerce → Integrations',
                'adoption' => ['channel', 'magento'],
            ],
            'indiamart' => [
                'label' => 'IndiaMART',
                'category' => 'marketplace',
                'icon' => 'pi pi-briefcase',
                'vendor' => 'IndiaMART',
                'status' => 'live',
                'module' => 'ecommerce',
                'description' => 'Pull buyer enquiries from Lead Manager straight into the CRM.',
                'configured_at' => 'Tenant → E-commerce → Integrations',
                'adoption' => ['channel', 'indiamart'],
            ],

            // ---------------------------------------------------------------
            // Roadmap — listed so the catalogue is the whole story, gated off
            // until the connector actually ships.
            // ---------------------------------------------------------------
            'shiprocket' => [
                'label' => 'Shiprocket',
                'category' => 'logistics',
                'icon' => 'pi pi-truck',
                'vendor' => 'Shiprocket',
                'status' => 'planned',
                'module' => 'logistics',
                'description' => 'Push dispatches for courier pickup, pull AWB numbers and delivery status back onto the order.',
                'configured_at' => null,
                'adoption' => ['none'],
            ],
            'tally' => [
                'label' => 'Tally',
                'category' => 'accounting',
                'icon' => 'pi pi-book',
                'vendor' => 'Tally Solutions',
                'status' => 'planned',
                'module' => 'accounting',
                'description' => 'Export vouchers, ledgers and masters to Tally Prime over its XML gateway.',
                'configured_at' => null,
                'adoption' => ['none'],
            ],
            'zoho' => [
                'label' => 'Zoho',
                'category' => 'accounting',
                'icon' => 'pi pi-briefcase',
                'vendor' => 'Zoho',
                'status' => 'planned',
                'module' => 'accounting',
                'description' => 'Sync contacts and invoices with Zoho Books / Zoho CRM.',
                'configured_at' => null,
                'adoption' => ['none'],
            ],
            'ondc' => [
                'label' => 'ONDC',
                'category' => 'network',
                'icon' => 'pi pi-sitemap',
                'vendor' => 'ONDC',
                'status' => 'planned',
                'module' => 'ecommerce',
                'description' => 'List the catalogue on the Open Network for Digital Commerce as a seller-side participant.',
                'configured_at' => null,
                'adoption' => ['none'],
            ],
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** True when the connector exists in code (as opposed to being roadmap). */
    public static function isLive(string $key): bool
    {
        return (self::get($key)['status'] ?? null) === 'live';
    }

    /**
     * Connected-tenant count per catalogue key, in as few queries as the shapes
     * allow (one per adoption source, not one per integration).
     *
     * @return array<string, int>
     */
    public static function adoption(): array
    {
        $counts = array_fill_keys(self::keys(), 0);

        // allTenants() lifts the row-level TenantScope: the platform panel runs
        // with no tenant context, so the default scope would return nothing.
        $channels = ChannelIntegration::allTenants()
            ->selectRaw('platform, count(distinct tenant_id) as aggregate')
            ->groupBy('platform')
            ->pluck('aggregate', 'platform');

        $gateways = PaymentGatewayAccount::allTenants()
            ->selectRaw('gateway, count(distinct tenant_id) as aggregate')
            ->groupBy('gateway')
            ->pluck('aggregate', 'gateway');

        $settings = Setting::allTenants()
            ->selectRaw('`key` as setting_key, value, count(distinct tenant_id) as aggregate')
            ->groupBy('key', 'value')
            ->get();

        foreach (self::all() as $key => $meta) {
            $adoption = $meta['adoption'];

            $counts[$key] = match ($adoption[0]) {
                'channel' => (int) ($channels[$adoption[1]] ?? 0),
                'gateway' => (int) ($gateways[$adoption[1]] ?? 0),
                'setting' => (int) $settings
                    ->where('setting_key', $adoption[1])
                    ->where('value', $adoption[2])
                    ->sum('aggregate'),
                'table' => (int) $adoption[1]::allTenants()->distinct()->count('tenant_id'),
                default => 0,
            };
        }

        return $counts;
    }
}
