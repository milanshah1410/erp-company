<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central-DB migration: the gateways the SaaS operator collects subscription
 * money through (Volume 11 §Billing → Payment Gateways).
 *
 * Distinct from the tenant-side `payment_gateway_accounts` table (SRS §16), which
 * is a workspace collecting from *its* customers. This one is the platform's own
 * merchant configuration, and there is at most one row per gateway.
 *
 * Credentials are encrypted at rest (the model casts them) and never leave the
 * server: only the publishable key id is handed to the browser for checkout, by
 * the controller that needs it. Rotating keys therefore means editing this row —
 * which is the point, since a .env edit needs a deploy.
 *
 * `is_default` marks the gateway new checkouts start at; a partial-unique index
 * is not portable, so the single default is enforced in the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payment_gateways', function (Blueprint $table) {
            $table->id();
            // razorpay | stripe | payu | paytm | cashfree — see App\Support\PlatformGateways.
            $table->string('gateway', 30)->unique();
            $table->string('label')->nullable();
            $table->string('mode', 8)->default('test'); // test | live
            // Encrypted JSON: {key_id, key_secret, webhook_secret, ...}
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->boolean('is_default')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // central_users.id
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_gateways');
    }
};
