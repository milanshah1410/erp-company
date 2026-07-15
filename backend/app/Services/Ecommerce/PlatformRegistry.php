<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Services\Ecommerce\Connectors\AmazonConnector;
use App\Services\Ecommerce\Connectors\FlipkartConnector;
use App\Services\Ecommerce\Connectors\IndiaMartConnector;
use App\Services\Ecommerce\Connectors\MagentoConnector;
use App\Services\Ecommerce\Connectors\MeeshoConnector;
use App\Services\Ecommerce\Connectors\ShopifyConnector;
use App\Services\Ecommerce\Connectors\WooCommerceConnector;

/**
 * Single source of truth for the supported e-commerce platforms: which
 * connector class handles each, what credential/setting fields the UI must
 * collect, and which sync capabilities the platform supports. The frontend
 * connect dialog is rendered from this same metadata (via the API), so the two
 * never drift.
 */
class PlatformRegistry
{
    /**
     * @return array<string,array{
     *   label:string, connector:class-string, icon:string,
     *   credentials:array<int,array{key:string,label:string,type:string,required:bool}>,
     *   settings:array<int,array{key:string,label:string,type:string,required:bool}>,
     *   capabilities:array{orders:bool,inventory:bool,listings:bool},
     *   help:string
     * }>
     */
    public static function all(): array
    {
        return [
            'shopify' => [
                'label' => 'Shopify',
                'connector' => ShopifyConnector::class,
                'icon' => 'pi-shopping-bag',
                'settings' => [
                    ['key' => 'shop_domain', 'label' => 'Shop domain (e.g. my-store.myshopify.com)', 'type' => 'text', 'required' => true],
                    ['key' => 'api_version', 'label' => 'API version', 'type' => 'text', 'required' => false],
                ],
                'credentials' => [
                    ['key' => 'access_token', 'label' => 'Admin API access token', 'type' => 'password', 'required' => true],
                ],
                'capabilities' => ['orders' => true, 'inventory' => true, 'listings' => true],
                'help' => 'Create a custom app in Shopify Admin → Apps, grant read_orders/read_products/write_inventory, and paste the Admin API access token.',
            ],
            'woocommerce' => [
                'label' => 'WooCommerce',
                'connector' => WooCommerceConnector::class,
                'icon' => 'pi-wordpress',
                'settings' => [
                    ['key' => 'store_url', 'label' => 'Store URL (https://…)', 'type' => 'text', 'required' => true],
                ],
                'credentials' => [
                    ['key' => 'consumer_key', 'label' => 'Consumer key (ck_…)', 'type' => 'password', 'required' => true],
                    ['key' => 'consumer_secret', 'label' => 'Consumer secret (cs_…)', 'type' => 'password', 'required' => true],
                ],
                'capabilities' => ['orders' => true, 'inventory' => true, 'listings' => true],
                'help' => 'WooCommerce → Settings → Advanced → REST API → Add key (Read/Write).',
            ],
            'magento' => [
                'label' => 'Magento',
                'connector' => MagentoConnector::class,
                'icon' => 'pi-shopping-cart',
                'settings' => [
                    ['key' => 'base_url', 'label' => 'Base URL (https://…)', 'type' => 'text', 'required' => true],
                ],
                'credentials' => [
                    ['key' => 'access_token', 'label' => 'Integration access token', 'type' => 'password', 'required' => true],
                ],
                'capabilities' => ['orders' => true, 'inventory' => true, 'listings' => true],
                'help' => 'Magento Admin → System → Integrations → create an integration with Sales & Catalog access and use its access token.',
            ],
            'amazon' => [
                'label' => 'Amazon',
                'connector' => AmazonConnector::class,
                'icon' => 'pi-amazon',
                'settings' => [
                    ['key' => 'marketplace_id', 'label' => 'Marketplace ID (e.g. A21TJRUUN4KGV for IN)', 'type' => 'text', 'required' => true],
                    ['key' => 'region', 'label' => 'Region endpoint (eu / na / fe)', 'type' => 'text', 'required' => true],
                    ['key' => 'seller_id', 'label' => 'Seller (merchant) ID', 'type' => 'text', 'required' => false],
                ],
                'credentials' => [
                    ['key' => 'lwa_client_id', 'label' => 'LWA client ID', 'type' => 'password', 'required' => true],
                    ['key' => 'lwa_client_secret', 'label' => 'LWA client secret', 'type' => 'password', 'required' => true],
                    ['key' => 'refresh_token', 'label' => 'SP-API refresh token', 'type' => 'password', 'required' => true],
                ],
                'capabilities' => ['orders' => true, 'inventory' => true, 'listings' => false],
                'help' => 'Requires an approved Amazon SP-API application. Provide the LWA credentials and a seller refresh token.',
            ],
            'flipkart' => [
                'label' => 'Flipkart',
                'connector' => FlipkartConnector::class,
                'icon' => 'pi-shopping-cart',
                'settings' => [],
                'credentials' => [
                    ['key' => 'application_id', 'label' => 'Application ID', 'type' => 'password', 'required' => true],
                    ['key' => 'application_secret', 'label' => 'Application secret', 'type' => 'password', 'required' => true],
                ],
                'capabilities' => ['orders' => true, 'inventory' => true, 'listings' => false],
                'help' => 'Flipkart Seller API app credentials (OAuth client-credentials). Access is granted from the Flipkart Seller portal.',
            ],
            'meesho' => [
                'label' => 'Meesho',
                'connector' => MeeshoConnector::class,
                'icon' => 'pi-shopping-bag',
                'settings' => [
                    ['key' => 'base_url', 'label' => 'Supplier API base URL', 'type' => 'text', 'required' => false],
                ],
                'credentials' => [
                    ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true],
                    ['key' => 'api_secret', 'label' => 'API secret', 'type' => 'password', 'required' => false],
                ],
                'capabilities' => ['orders' => true, 'inventory' => true, 'listings' => false],
                'help' => 'Meesho Supplier / Valmo partner API credentials. Access is granted per supplier account.',
            ],
            'indiamart' => [
                'label' => 'IndiaMART',
                'connector' => IndiaMartConnector::class,
                'icon' => 'pi-briefcase',
                'settings' => [],
                'credentials' => [
                    ['key' => 'crm_key', 'label' => 'Lead Manager CRM key', 'type' => 'password', 'required' => true],
                ],
                'capabilities' => ['orders' => true, 'inventory' => false, 'listings' => false],
                'help' => 'IndiaMART is lead-based: the Lead Manager (Push API) CRM key pulls buyer enquiries, which are imported as leads/orders.',
            ],
        ];
    }

    public static function has(string $platform): bool
    {
        return array_key_exists($platform, self::all());
    }

    /** @return array{label:string,connector:class-string,...}|null */
    public static function get(string $platform): ?array
    {
        return self::all()[$platform] ?? null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Metadata for the frontend, without exposing the connector class names. */
    public static function forFrontend(): array
    {
        $out = [];
        foreach (self::all() as $key => $meta) {
            $out[] = [
                'platform' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'credentials' => $meta['credentials'],
                'settings' => $meta['settings'],
                'capabilities' => $meta['capabilities'],
                'help' => $meta['help'],
            ];
        }

        return $out;
    }
}
