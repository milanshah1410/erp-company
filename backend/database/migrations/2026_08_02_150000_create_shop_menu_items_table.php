<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop-wise menu templates — the navigation a *trade* starts on.
 *
 * `menu_items` already holds two kinds of row: the global catalogue (tenant_id
 * NULL — every screen the product ships) and each workspace's own copy of it.
 * What sat between them was nothing: a kirana shop and a jewellery showroom were
 * both provisioned from the same flat 240-row catalogue and left to the operator
 * to prune by hand, one workspace at a time.
 *
 * A row here is one entry in one trade's starting menu. At provisioning time
 * App\Support\ShopMenus clones the template for the tenant's trade instead of the
 * whole catalogue, so a grocery signup opens on a grocery menu.
 *
 * Deliberately a separate table rather than a `shop_profile` column on
 * `menu_items`: thirteen places already read the global catalogue as
 * `whereNull('tenant_id')`, and every one of them would have had to learn to
 * exclude template rows. A template is also a different kind of thing — it names
 * screens that exist rather than defining them, so it carries no authority the
 * catalogue does not already have.
 *
 * `shop_profile` is the trade key from App\Support\ShopProfiles ('garment',
 * 'grocery', …) — the same key space as `tenants.business_type` and the
 * `shop_profile` tenant setting. Not a foreign key, because trades are declared
 * in code (industry plugins), not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('shop_profile', 64)->index();
            // Cascade rather than nullOnDelete (which is what menu_items uses):
            // a template row detached from its group is meaningless, whereas a
            // tenant's orphaned row is at least still a screen they can reach.
            $table->foreignId('parent_id')->nullable()->constrained('shop_menu_items')->cascadeOnDelete();
            $table->string('label');
            $table->string('icon')->nullable();
            $table->string('route')->nullable();       // null = a group header
            $table->string('permission')->nullable();  // role gate, copied from the catalogue
            $table->string('feature')->nullable();     // subscription plan gate
            $table->string('module')->nullable();      // tenant module gate
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_profile', 'parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_menu_items');
    }
};
