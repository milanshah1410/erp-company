<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jewellery: gold purity and making charges.
 *
 * A jeweller's price list does not exist. An ornament is worth its metal at
 * today's rate for its purity, plus a making charge, plus whatever stones are
 * set in it — so `products.selling_price` is meaningless here and the price is
 * computed at the moment of sale (JewelleryPricingService).
 *
 * Two consequences shape this schema:
 *
 *  1. The rate is *dated*. Today's 22K rate prices today's bill; tomorrow's
 *     rate must not restate yesterday's invoice. So rates are a dated master
 *     and the rate actually used is snapshotted onto the sale line.
 *
 *  2. Weight is not one number. Gross includes the stones, net is the metal
 *     alone, and only the net weight is worth the metal rate. Charging metal on
 *     gross weight is the classic way a jewellery bill comes out wrong.
 *
 * Old-gold exchange is here too because a jeweller cannot actually transact
 * without it: most purchases are part-settled with the customer's own scrap.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dated rate card. A shop quotes "22K = 6,500/gram" — the rate already
        // accounts for purity, so no fineness maths is needed to price a sale.
        // `fineness` is kept anyway because old scrap of an odd purity is valued
        // off the 24K rate in proportion to it.
        Schema::create('metal_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('metal', ['gold', 'silver', 'platinum'])->default('gold');
            // '24K', '22K', '18K', '925' … as the trade writes it.
            $table->string('purity', 10);
            // Parts per thousand: 22K = 916, 18K = 750, sterling silver = 925.
            $table->unsignedSmallInteger('fineness');
            $table->decimal('rate_per_gram', 12, 2);
            // What the shop pays for scrap of this purity, usually a little
            // under the selling rate. Null falls back to the selling rate.
            $table->decimal('buy_rate_per_gram', 12, 2)->nullable();
            $table->date('effective_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One rate per metal+purity per day; re-quoting the same day updates
            // it rather than stacking a second row the pricing would race on.
            $table->unique(['tenant_id', 'metal', 'purity', 'effective_on'], 'metal_rates_unique_day');
            $table->index(['tenant_id', 'effective_on']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Null metal means "not a jewellery item" — the pricing service uses
            // it to decide whether a line is rate-priced at all.
            $table->enum('metal', ['gold', 'silver', 'platinum'])->nullable()->after('manufacturer');
            $table->string('purity', 10)->nullable()->after('metal');
            // Gross includes stones; net is the metal that earns the rate.
            $table->decimal('gross_weight', 12, 3)->nullable()->after('purity');
            $table->decimal('net_weight', 12, 3)->nullable()->after('gross_weight');
            $table->decimal('stone_weight', 12, 3)->nullable()->after('net_weight');
            // Flat value of the stones — priced by the piece, never by the gram.
            $table->decimal('stone_value', 15, 2)->default(0)->after('stone_weight');
            /**
             * How the making charge is quoted. All three are in daily use:
             *   percent   — % of the metal value
             *   per_gram  — ₹ per gram of net weight
             *   flat      — ₹ for the piece
             */
            $table->enum('making_charge_type', ['percent', 'per_gram', 'flat'])
                ->default('percent')->after('stone_value');
            $table->decimal('making_charge_value', 12, 2)->default(0)->after('making_charge_type');
            // Extra metal charged for what is lost in working the piece.
            $table->decimal('wastage_percent', 6, 3)->default(0)->after('making_charge_value');
            // BIS hallmark unique id — mandatory on hallmarked gold.
            $table->string('huid', 20)->nullable()->after('wastage_percent');

            $table->index(['tenant_id', 'metal']);
        });

        // The rate and weights a line was actually billed at. Without this an
        // old invoice would silently re-price the moment the rate card moves.
        Schema::table('sales_items', function (Blueprint $table) {
            $table->decimal('metal_rate', 12, 2)->nullable()->after('unit_price');
            $table->decimal('net_weight', 12, 3)->nullable()->after('metal_rate');
            $table->decimal('metal_value', 15, 2)->nullable()->after('net_weight');
            $table->decimal('making_charge', 15, 2)->nullable()->after('metal_value');
            $table->decimal('wastage_value', 15, 2)->nullable()->after('making_charge');
            $table->decimal('stone_value', 15, 2)->nullable()->after('wastage_value');
        });

        // Old gold brought in by the customer: weighed, purity-tested, valued at
        // the buy rate and set off against the bill.
        Schema::create('old_gold_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Set once the scrap is used to settle a bill; null while it is
            // still an open credit the customer is holding.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();

            $table->enum('metal', ['gold', 'silver', 'platinum'])->default('gold');
            $table->string('purity', 10);
            $table->unsignedSmallInteger('fineness');
            $table->string('description')->nullable();

            $table->decimal('gross_weight', 12, 3);
            // What is left after stones and solder are deducted — the weight
            // the customer is actually paid for.
            $table->decimal('net_weight', 12, 3);
            // Touch/melting loss the shop deducts, in percent of net weight.
            $table->decimal('deduction_percent', 6, 3)->default(0);
            $table->decimal('chargeable_weight', 12, 3);
            $table->decimal('rate_per_gram', 12, 2);
            $table->decimal('amount', 15, 2);

            $table->date('received_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'received_on']);
        });

        if ($this->isMysql()) {
            // Old gold settles part of a bill exactly the way an exchange credit
            // or redeemed points do — as a tender, not a discount.
            DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit','loyalty','exchange','old_gold') NOT NULL DEFAULT 'cash'");
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('payments')->where('mode', 'old_gold')->update(['mode' => 'credit']);
            DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit','loyalty','exchange') NOT NULL DEFAULT 'cash'");
        }

        Schema::dropIfExists('old_gold_receipts');

        Schema::table('sales_items', function (Blueprint $table) {
            $table->dropColumn([
                'metal_rate', 'net_weight', 'metal_value',
                'making_charge', 'wastage_value', 'stone_value',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'metal']);
            $table->dropColumn([
                'metal', 'purity', 'gross_weight', 'net_weight', 'stone_weight',
                'stone_value', 'making_charge_type', 'making_charge_value',
                'wastage_percent', 'huid',
            ]);
        });

        Schema::dropIfExists('metal_rates');
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
