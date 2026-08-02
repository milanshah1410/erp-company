<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift vouchers — a Loyalty & Offers feature, not a garment one.
 *
 * The garment trade is what prompted them, but nothing about a voucher is
 * apparel-specific: a gift shop, a supermarket, a bookstore and a salon all sell
 * the same instrument. More decisively, a voucher is *spent* through
 * PosController, and core cannot be made to depend on an industry plugin that
 * may not be installed — so this sits in core alongside coupons and loyalty
 * points, gated on the `loyalty` module like both of them.
 *
 * Two modelling decisions worth stating:
 *
 *  1. A voucher carries a **balance**, not a used flag. A ₹2,000 voucher against
 *     a ₹1,450 bill is the ordinary case, and the shop still owes ₹550. A
 *     boolean would either lose that or force the counter to refuse the sale.
 *
 *  2. Redemptions are a **signed ledger**. A spend is positive, a reversal
 *     (goods returned against a bill the voucher paid for) is negative, so the
 *     balance is always reconstructible as face value minus their sum — one
 *     arithmetic rule instead of a spends table and a credits table that have to
 *     be kept in agreement.
 *
 * The tender enum gains 'voucher' for the same reason it gained 'loyalty' and
 * 'old_gold': a voucher settles part of a bill, so it belongs in the tender mix.
 * Recording it as a discount would restate the taxable value of a sale that was
 * rung up at full price — the shop sold ₹1,450 of goods either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // What is printed on the card and typed at the till.
            $table->string('code', 40);

            // Who it was issued to, if anyone. Nullable because the commonest
            // gift voucher is bought by one person for an unnamed other.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_phone', 20)->nullable();

            $table->decimal('face_value', 12, 2);
            $table->decimal('balance', 12, 2);

            $table->date('issued_on');
            $table->date('expires_on')->nullable();
            /**
             * active → redeemed when the balance reaches zero. `expired` and
             * `cancelled` are set explicitly; expiry is *also* derived on read
             * (GiftVoucher::isExpired) so a voucher that lapsed at midnight
             * cannot be spent at nine the next morning merely because nothing
             * has swept the table.
             */
            $table->enum('status', ['active', 'redeemed', 'expired', 'cancelled'])->default('active');

            // The sale that sold the voucher, when it was sold rather than
            // issued as compensation or a promotion.
            $table->foreignId('issued_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'gift_vouchers_code_unique');
            $table->index(['tenant_id', 'status'], 'gift_vouchers_status_idx');
            $table->index(['tenant_id', 'customer_id'], 'gift_vouchers_customer_idx');
        });

        Schema::create('gift_voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_voucher_id')->constrained('gift_vouchers')->cascadeOnDelete();
            // Signed: positive is a spend, negative is a reversal.
            $table->decimal('amount', 12, 2);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->timestamp('redeemed_at');
            $table->string('reference', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'gift_voucher_id'], 'gift_voucher_redemptions_voucher_idx');
            $table->index(['tenant_id', 'invoice_id'], 'gift_voucher_redemptions_invoice_idx');
        });

        if ($this->isMysql()) {
            DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit','loyalty','exchange','old_gold','voucher') NOT NULL DEFAULT 'cash'");
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            // MySQL truncates rows holding a dropped enum value to '', so move
            // them somewhere readable first. 'credit' is the closest honest
            // label — a voucher is not cash.
            DB::table('payments')->where('mode', 'voucher')->update(['mode' => 'credit']);
            DB::statement("ALTER TABLE payments MODIFY mode ENUM('cash','card','upi','credit','loyalty','exchange','old_gold') NOT NULL DEFAULT 'cash'");
        }

        Schema::dropIfExists('gift_voucher_redemptions');
        Schema::dropIfExists('gift_vouchers');
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
