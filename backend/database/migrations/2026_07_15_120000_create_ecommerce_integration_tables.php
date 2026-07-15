<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-commerce Integration (SRS §15): connect the ERP to external marketplaces
 * and storefronts (Shopify, WooCommerce, Magento, Amazon, Flipkart, Meesho,
 * IndiaMART). Each `channel_integration` is one connected account; product
 * `channel_listings` map local products to their external SKU/listing; pulled
 * marketplace orders land in `channel_orders` (then import into the normal
 * sales pipeline as SalesOrders on the 'ecommerce' channel); every sync run is
 * recorded in `channel_sync_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30); // shopify, woocommerce, magento, amazon, flipkart, meesho, indiamart
            $table->string('name');
            $table->enum('status', ['disconnected', 'connected', 'error'])->default('disconnected');
            $table->text('credentials')->nullable();      // encrypted JSON (api keys/tokens/secrets)
            $table->json('settings')->nullable();          // non-secret options (store url, currency, etc.)
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete(); // stock source / order destination
            $table->boolean('auto_sync_orders')->default(false);
            $table->boolean('auto_push_inventory')->default(false);
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_inventory_sync_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'platform']);
        });

        Schema::create('channel_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('external_id')->nullable();     // marketplace listing/product id
            $table->string('external_sku')->nullable();    // marketplace SKU (used for inventory push matching)
            $table->string('listing_title')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_integration_id', 'external_id']);
            $table->index(['channel_integration_id', 'external_sku']);
            $table->index(['channel_integration_id', 'product_id', 'variant_id'], 'channel_listings_product_idx');
        });

        Schema::create('channel_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_integration_id')->constrained()->cascadeOnDelete();
            $table->string('external_order_id');
            $table->string('external_order_number')->nullable();
            $table->string('order_status')->nullable();       // marketplace-native status label
            $table->string('financial_status')->nullable();   // paid / pending / cod, etc.
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->json('shipping_address')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->string('currency', 8)->default('INR');
            $table->timestamp('placed_at')->nullable();
            $table->json('items')->nullable();                // normalised line items snapshot
            $table->json('raw')->nullable();                  // original payload (truncated) for audit
            $table->enum('import_status', ['pending', 'imported', 'ignored', 'failed'])->default('pending');
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('import_error')->nullable();
            $table->timestamps();

            $table->unique(['channel_integration_id', 'external_order_id']);
            $table->index(['tenant_id', 'import_status']);
        });

        Schema::create('channel_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_integration_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['connection', 'orders', 'inventory', 'listings']);
            $table->enum('direction', ['pull', 'push'])->default('pull');
            $table->enum('status', ['success', 'partial', 'failed']);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('message')->nullable();
            $table->timestamps();

            $table->index(['channel_integration_id', 'type']);
        });

        // Widen the sales_orders channel enum to accept marketplace-sourced
        // orders. Raw MODIFY is MySQL-only; SQLite (test DB) stores enums as
        // unconstrained strings, so the new value works without it.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce') NOT NULL DEFAULT 'standard'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sync_logs');
        Schema::dropIfExists('channel_orders');
        Schema::dropIfExists('channel_listings');
        Schema::dropIfExists('channel_integrations');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b') NOT NULL DEFAULT 'standard'");
        }
    }
};
