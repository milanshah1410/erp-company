<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volume 11 §"Industry Templates" — the vertical catalogue the SaaS owner sells.
 *
 * Why a table and not another PHP constant: an industry template is *pricing and
 * packaging*, not code. The owner needs to add "Education" on a Tuesday, rename
 * "Logistics" to "Transport & Logistics", or retire one — none of which should
 * need a deploy. Code-declared industry *plugins* (app/Modules/Industry) stay
 * exactly as they are: a plugin ships behaviour (tables, screens, routes), while
 * a template ships a starting point (which trades, which modules). A plugin's
 * vertical is seeded here as a system row pointing back at it via `plugin_key`,
 * so the two lists never disagree about what verticals exist.
 *
 * `trades` holds ShopProfiles keys. It is deliberately a JSON list rather than a
 * pivot table: trades themselves are code (a preset's module set is only
 * meaningful next to the module catalogue), so this column is a curation of
 * existing keys, not a foreign-key relationship. Unknown keys are dropped on
 * read (App\Support\IndustryTemplates), which keeps a renamed trade from
 * breaking the onboarding picker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label');
            $table->string('icon', 60)->default('pi pi-briefcase');
            $table->string('description')->nullable();
            // Default module set when a tenant picks the industry but no trade.
            $table->json('modules')->nullable();
            // ShopProfiles trade keys offered under this industry.
            $table->json('trades')->nullable();
            // Set when this template fronts a code-declared industry plugin:
            // its trades come from the plugin, so the JSON column stays empty.
            $table->string('plugin_key', 50)->nullable();
            // System rows are the seeded catalogue: editable, but not deletable —
            // dropping one would orphan every tenant provisioned under it.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_templates');
    }
};
