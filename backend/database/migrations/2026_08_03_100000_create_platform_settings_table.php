<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central-DB migration: key/value settings owned by the SaaS operator itself.
 *
 * The `settings` table is tenant-scoped — every row belongs to a workspace — so
 * it cannot hold the platform's own configuration. This is the landlord-side
 * equivalent: the operator's billing identity (legal name, GSTIN, place of
 * supply), the tax defaults its subscription invoices are raised with, and the
 * invoice-number series. Values are JSON so a setting can hold a scalar or a
 * small map without a second table.
 *
 * See App\Support\PlatformBillingProfile — the only reader of the billing keys,
 * which layers config() defaults underneath anything stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
