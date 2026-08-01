<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central-DB migration: pin a coupon to the subscription it discounts.
 *
 * A `once` coupon needs nothing here — the invoice that used it records the
 * redemption and the story ends. A `repeating` or `forever` coupon has to survive
 * until the next renewal, which is what these columns carry:
 * `coupon_cycles_remaining` counts down on each invoice it discounts (null =
 * forever) and the coupon is detached when it runs out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_coupon_id')->nullable()->index()->after('pending_plan_id');
            $table->string('coupon_code', 40)->nullable()->after('platform_coupon_id');
            // null with a coupon attached = forever; 0 is normalised to detached.
            $table->unsignedSmallInteger('coupon_cycles_remaining')->nullable()->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['platform_coupon_id']);
            $table->dropColumn(['platform_coupon_id', 'coupon_code', 'coupon_cycles_remaining']);
        });
    }
};
