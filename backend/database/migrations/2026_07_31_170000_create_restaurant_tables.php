<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant: kitchen orders and table management.
 *
 * A restaurant order is not a counter sale. The POS elsewhere in this system is
 * atomic — scan, tender, done — but a table is occupied for an hour, food is
 * ordered in rounds, and the bill is settled once at the end. So the order here
 * is a long-lived open ticket that accumulates items, and the kitchen is fed by
 * KOTs: batches fired off as they are ordered.
 *
 * Three invariants shape the schema:
 *
 *  1. A table holds at most one open order. Everything about seating, billing
 *     and "is table 7 free?" falls out of that, so it is enforced with a
 *     partial-unique index rather than left to application code.
 *
 *  2. An item that has gone to the kitchen cannot be deleted, only voided with
 *     a reason. The food was cooked; the loss belongs to somebody, and silently
 *     removing the line hides it.
 *
 *  3. KOTs are split by station. The bar does not need the tandoor's dockets,
 *     so the station lives on the product and firing groups by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // "AC Hall", "Garden", "Rooftop" — often priced or staffed apart.
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'restaurant_sections_name_unique');
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_section_id')->nullable()
                ->constrained('restaurant_sections')->nullOnDelete();
            $table->string('name', 30);
            $table->unsignedSmallInteger('capacity')->default(4);
            /**
             * Derived from the open order in practice, but stored so the floor
             * view is one cheap query and so 'reserved' and 'cleaning' — which
             * have no order behind them — can be represented at all.
             */
            $table->enum('status', ['free', 'occupied', 'reserved', 'cleaning'])->default('free');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'restaurant_tables_name_unique');
            $table->index(['tenant_id', 'status']);
        });

        // Which station cooks it. Null means the main kitchen.
        Schema::table('products', function (Blueprint $table) {
            $table->enum('kitchen_station', ['kitchen', 'bar', 'tandoor', 'dessert', 'chinese'])
                ->nullable()->after('is_frame');
            // Preparation minutes, so the floor can promise a time.
            $table->unsignedSmallInteger('prep_minutes')->nullable()->after('kitchen_station');
        });

        $isMysql = $this->isMysql();

        Schema::create('restaurant_orders', function (Blueprint $table) use ($isMysql) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            $table->enum('order_type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');
            /**
             * Null for takeaway and delivery — they occupy no table.
             *
             * Restricted rather than set-null on delete: the open_table_key
             * generated column below is derived from this one, and MySQL will
             * not let a SET NULL cascade into a generated column. Restricting
             * is the honest rule anyway — a table with order history is marked
             * inactive, never deleted, which is what the controller enforces.
             */
            $table->foreignId('restaurant_table_id')->nullable()
                ->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // The waiter the table is attributed to, for tips and performance.
            $table->foreignId('waiter_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->enum('status', ['open', 'settled', 'cancelled'])->default('open');
            $table->unsignedSmallInteger('guest_count')->default(1);

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('item_discount_total', 15, 2)->default(0);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            // Service charge is a restaurant-specific line the customer sees
            // separately from tax, so it cannot ride inside the discount fields.
            $table->decimal('service_charge_percent', 5, 2)->default(0);
            $table->decimal('service_charge', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('round_off', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number'], 'restaurant_orders_number_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'restaurant_table_id', 'status'], 'rest_orders_table_status_idx');

            if ($isMysql) {
                // One open order per table, enforced by the database rather than
                // by application code, because two waiters seating the same
                // table at the same moment is exactly the race a check-then-act
                // in PHP loses.
                //
                // MySQL has no partial unique index, so this is the standard
                // stand-in: a generated column that is NULL (and therefore never
                // compared) unless the order is open, unique with the tenant.
                // It has to be declared here rather than added later — MySQL
                // refuses to ALTER a stored generated column onto a table that
                // already carries foreign keys.
                $table->unsignedBigInteger('open_table_key')->nullable()
                    ->storedAs("CASE WHEN status = 'open' THEN restaurant_table_id ELSE NULL END");
                $table->unique(['tenant_id', 'open_table_key'], 'rest_orders_one_open_per_table');
            }
        });

        Schema::create('kitchen_order_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kot_number');
            $table->foreignId('restaurant_order_id')->constrained('restaurant_orders')->cascadeOnDelete();
            $table->enum('station', ['kitchen', 'bar', 'tandoor', 'dessert', 'chinese'])->default('kitchen');
            /**
             * The kitchen display's lifecycle. 'ready' is separate from 'served'
             * because the pass calls ready and the floor calls served, and the
             * gap between them is the number a manager actually watches.
             */
            $table->enum('status', ['pending', 'preparing', 'ready', 'served', 'cancelled'])->default('pending');
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'kot_number'], 'kots_number_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_order_id')->constrained('restaurant_orders')->cascadeOnDelete();
            // Set when the line is fired; null while it is still being keyed in.
            $table->foreignId('kitchen_order_ticket_id')->nullable()
                ->constrained('kitchen_order_tickets')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);

            /**
             * pending — keyed in, not yet with the kitchen (freely editable)
             * sent    — on a KOT; can only be voided from here, never deleted
             * served  — on the table
             * void    — cancelled after firing, with a reason
             */
            $table->enum('status', ['pending', 'sent', 'served', 'void'])->default('pending');
            $table->string('void_reason')->nullable();
            // "less spicy", "no onion" — printed on the KOT, not on the bill.
            $table->string('instructions')->nullable();
            $table->unsignedTinyInteger('course')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'restaurant_order_id', 'status'], 'rest_items_order_status_idx');
        });

        if ($isMysql) {
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce','service','project','optical','restaurant') NOT NULL DEFAULT 'standard'");
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            // The generated column and its index live on restaurant_orders and
            // go with the table below; only the shared enum needs unwinding.
            DB::table('sales_orders')->where('channel', 'restaurant')->update(['channel' => 'standard']);
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce','service','project','optical') NOT NULL DEFAULT 'standard'");
        }

        Schema::dropIfExists('restaurant_order_items');
        Schema::dropIfExists('kitchen_order_tickets');
        Schema::dropIfExists('restaurant_orders');
        Schema::dropIfExists('restaurant_tables');
        Schema::dropIfExists('restaurant_sections');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['kitchen_station', 'prep_minutes']);
        });
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
