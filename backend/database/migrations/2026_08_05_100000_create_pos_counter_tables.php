<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The till register: which counters a shop has, and the shift each one is
 * currently working.
 *
 * Core, gated `module:pos`, and deliberately *not* part of the Supermarket
 * module even though a supermarket is what forced it. The reasoning is the one
 * gift vouchers already established: a two-till kirana, a garment showroom with
 * a first-floor cash desk and a jeweller with a separate silver counter all end
 * the day the same way — count the drawer, compare it with what the system says
 * should be in it, explain the difference. That is a POS feature, and putting it
 * behind a supermarket switch would have hidden it from every shop that has more
 * than one till and is not a supermarket.
 *
 * What the Supermarket module adds on top is the *unattended* lane
 * (`pos_counters.mode = 'self_checkout'`), which is a supermarket thing and is
 * worked through the Retail plugin's self-checkout screens. The column lives here
 * rather than in the plugin because the counter row is core and a plugin must not
 * own a column core code reads.
 *
 * Three changes:
 *
 *  1. `pos_counters` — the physical desks. A master, not a document: a counter
 *     is created once when the lane is built and edited when its printer moves.
 *
 *  2. `pos_counter_sessions` — one row per shift at one counter. The money
 *     columns are the whole point: `opening_float` is what was put in the drawer
 *     before trading, `declared_cash` is what the cashier counted at the end,
 *     `expected_cash` is what the system says should have been there, and
 *     `variance` is the difference that somebody has to explain. Expected and
 *     variance are *snapshotted at close* rather than derived on every read —
 *     a shift that reconciled to the rupee in March must still say so in
 *     December, whatever has been edited since.
 *
 *  3. `payments.pos_session_id` and `sales_orders.pos_counter_id` /
 *     `pos_session_id` — the stamps. Two different questions need two different
 *     stamps and it is worth being explicit about why:
 *
 *       - The **drawer** is a payments question. Cash refunded today against a
 *         bill sold last Tuesday leaves *today's* drawer, so attributing money
 *         by walking back to the sale's session would put the shortfall in the
 *         wrong shift. Payments carry the session; expected cash is summed off
 *         them and a negative refund row nets itself off.
 *       - **Sales by till** is an order question, and an order also wants the
 *         counter directly so "what did lane 4 ring up this month" does not
 *         have to join through every session it ever ran.
 *
 *     All three are nullable and `nullOnDelete`. A shop that never defines a
 *     counter carries three unused columns and behaves exactly as it did before,
 *     which is the point: the till goes on working untouched until somebody
 *     opens a session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * The warehouse the lane sells out of. Required: a bill rung up at a
             * counter has to take stock off somewhere, and a counter that does
             * not know its own store is a counter whose sales cannot be posted.
             */
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            /**
             * 'attended' is a cashier's desk; 'self_checkout' is a lane the
             * customer works themselves under a supervisor's eye. The
             * distinction is not cosmetic — a self-checkout lane refuses tenders
             * that need a human (credit, cheque-like instruments) and routes
             * flagged baskets to an attendant before they can be paid for.
             */
            $table->enum('mode', ['attended', 'self_checkout'])->default('attended');
            // Where the lane physically is — "Front row, near exit". Printed on
            // nothing; it exists so a supervisor chasing a variance knows which
            // desk to walk to.
            $table->string('location', 120)->nullable();
            /**
             * What the drawer is floated with by default. A default rather than
             * a rule: the cashier can key a different figure when they open, and
             * that figure is what the shift reconciles against.
             */
            $table->decimal('default_float', 12, 2)->default(0);
            /**
             * The printer and the customer display bolted to this lane. Nullable
             * and nullOnDelete — a device unplugged from the Device Manager must
             * not take the counter with it.
             */
            $table->foreignId('printer_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'pos_counters_code_unique');
            $table->index(['tenant_id', 'is_active'], 'pos_counters_active_idx');
        });

        Schema::create('pos_counter_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counter_id')->constrained('pos_counters')->cascadeOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 12, 2)->default(0);

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            // What the cashier physically counted. Null until the shift closes.
            $table->decimal('declared_cash', 12, 2)->nullable();
            // What the system said should be there, snapshotted at close.
            $table->decimal('expected_cash', 12, 2)->nullable();
            // declared − expected. Negative is short, positive is over. Stored
            // rather than computed so a report never re-derives a number the
            // shift already signed off.
            $table->decimal('variance', 12, 2)->nullable();
            // The non-cash totals as at close, for the Z report. Kept as a JSON
            // snapshot rather than columns because the tender list grows (it is
            // already eight modes) and a shift's report must show the modes that
            // existed the day it was taken, not the ones that exist today.
            $table->json('tender_totals')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            /**
             * One open session per counter, enforced in the database rather than
             * only in the service. Two open drawers on one lane would make every
             * expected-cash figure meaningless, and a race between two cashiers
             * tapping "Open" is exactly the way it would happen.
             *
             * The trick is that MySQL treats NULLs as distinct in a unique index,
             * so a nullable mirror of `status` gives "at most one open row, any
             * number of closed ones" for free.
             */
            $table->enum('open_flag', ['open'])->nullable();
            $table->unique(['tenant_id', 'counter_id', 'open_flag'], 'pos_sessions_one_open_idx');
            $table->index(['tenant_id', 'counter_id', 'opened_at'], 'pos_sessions_counter_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('pos_session_id')->nullable()->after('mode')
                ->constrained('pos_counter_sessions')->nullOnDelete();
            $table->index(['tenant_id', 'pos_session_id'], 'payments_session_idx');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('pos_counter_id')->nullable()->after('channel')
                ->constrained('pos_counters')->nullOnDelete();
            $table->foreignId('pos_session_id')->nullable()->after('pos_counter_id')
                ->constrained('pos_counter_sessions')->nullOnDelete();
            $table->index(['tenant_id', 'pos_counter_id'], 'sales_orders_counter_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_counter_idx');
            $table->dropConstrainedForeignId('pos_session_id');
            $table->dropConstrainedForeignId('pos_counter_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_session_idx');
            $table->dropConstrainedForeignId('pos_session_id');
        });

        Schema::dropIfExists('pos_counter_sessions');
        Schema::dropIfExists('pos_counters');
    }
};
