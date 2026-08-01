<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volume 11 §"Integration Marketplace", §"Device Marketplace" and §"Tenant Usage".
 *
 * Two platform-owned tables, both on the shared (central) side:
 *
 *  - `marketplace_items` — the SaaS owner's decision about one catalogue entry:
 *    is it on sale, to whom, and with what caveat. The entry itself (label,
 *    fields, driver class) stays in code — App\Support\IntegrationCatalogue and
 *    the DeviceManager registry — because shipping a connector is a deploy, not
 *    a data edit. This table only carries what an operator may change at 2am
 *    without a release: enable/disable, plan availability, beta badge, note.
 *
 *  - `tenant_usage_daily` — one row per tenant/day/metric. Counters (API calls,
 *    AI calls) accumulate through the day; snapshots (storage, products,
 *    customers) are overwritten with the latest reading for that day. Keeping
 *    both shapes in one table means the trend chart and the totals strip read a
 *    single place, and a metric can change kind later without a schema move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_items', function (Blueprint $table) {
            $table->id();

            // 'integration' | 'device' — one table, because the operator levers
            // are identical and a Module Marketplace will want the same three.
            $table->string('kind', 20);
            // Catalogue key. Integrations: 'razorpay'. Devices: 'barcode' for a
            // whole class, 'barcode:zebra' for one brand adapter.
            $table->string('key', 120);

            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_beta')->default(false);
            // null = every plan. A list restricts the offering to those slugs.
            $table->json('plan_slugs')->nullable();
            $table->string('note', 255)->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['kind', 'key']);
            $table->index(['kind', 'is_enabled']);
        });

        Schema::create('tenant_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->date('date');
            $table->string('metric', 40);
            // Bytes for storage, plain counts elsewhere — unsigned big int so a
            // storage figure never overflows on a fat tenant.
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'date', 'metric']);
            // Drives the platform-wide totals strip: one metric across all
            // tenants for a date window.
            $table->index(['metric', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_daily');
        Schema::dropIfExists('marketplace_items');
    }
};
