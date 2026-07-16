<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic, database-driven navigation (project-stack.md §3).
 *
 * - `menu_items` is a shared catalogue (tenant_id NULL = global default, exactly
 *   like the role/permission set). A tenant MAY add its own rows (tenant_id set)
 *   for a tenant-specific menu. Visibility is decided per-user at request time by
 *   the item's `permission` (role-based) and `feature` (subscription-based).
 * - `menu_favorites` / `menu_recents` are per-user, per-tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            // NULL = global catalogue row shared by every tenant; set = a
            // tenant-specific custom item. Not row-scoped via TenantScope so the
            // global rows remain visible to all tenants (see MenuItem model).
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->string('label');
            $table->string('icon')->nullable();
            $table->string('route')->nullable();           // null for a group/section header
            $table->string('permission')->nullable();      // role-based gate
            $table->string('feature')->nullable();          // subscription plan feature gate
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'parent_id', 'sort_order']);
        });

        Schema::create('menu_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'menu_item_id']);
        });

        Schema::create('menu_recents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('route');
            $table->string('label');
            $table->string('icon')->nullable();
            $table->timestamp('visited_at');
            $table->timestamps();

            $table->unique(['user_id', 'route']);
            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_recents');
        Schema::dropIfExists('menu_favorites');
        Schema::dropIfExists('menu_items');
    }
};
