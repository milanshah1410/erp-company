<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salesman (field-sales / beat) module.
 *
 * A salesman is a field agent assigned a monthly sales target and a set of
 * "beats" (recurring routes of customers). Each working day they run a visit
 * plan against a beat, checking in at each customer with a GPS fix; while in
 * the field they book orders, collect payments and log daily expenses. The
 * mobile app streams live-location pings for tracking. Incentive is derived
 * from target achievement (see SalesmanIncentiveService).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Salesman master.
        Schema::create('salesmen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            // Optional links: a login user and/or an HR employee record.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('territory')->nullable();
            $table->date('joining_date')->nullable();
            // Fallback incentive rate when a target has no explicit override.
            $table->decimal('default_incentive_percent', 5, 2)->default(0);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        // Monthly sales target per salesman. Achievement is computed live from
        // the salesman's orders in the period, not stored here.
        Schema::create('salesman_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1-12
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->unsignedInteger('target_orders')->nullable();
            // Incentive rate for this period; overrides the salesman default.
            $table->decimal('incentive_percent', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'salesman_id', 'period_year', 'period_month'], 'salesman_targets_period_unique');
        });

        // Beat plan header: a named, optionally weekday-scheduled route.
        Schema::create('beats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->string('name');
            // The recurring weekday this beat is run on (null = ad-hoc).
            $table->enum('day_of_week', ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])->nullable();
            $table->string('area')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'salesman_id']);
        });

        // Ordered customer stops within a beat (route planning sequence).
        Schema::create('beat_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beat_id')->constrained('beats')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'beat_id', 'customer_id'], 'beat_customers_unique');
            $table->index(['tenant_id', 'beat_id']);
        });

        // A single planned/actual customer visit on a given day. Planned rows
        // are generated from a beat; check-in stamps the GPS fix and time.
        Schema::create('salesman_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('beat_id')->nullable()->constrained('beats')->nullOnDelete();
            $table->date('visit_date');
            $table->unsignedInteger('sequence')->default(0);
            $table->enum('status', ['planned', 'visited', 'skipped', 'cancelled'])->default('planned');
            $table->string('purpose')->nullable();
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->string('outcome')->nullable(); // e.g. order, collection, no-order
            // The order booked during this visit, if any.
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'salesman_id', 'visit_date']);
            $table->index(['tenant_id', 'status']);
        });

        // GPS location pings streamed from the mobile app. Live location is the
        // most recent row per salesman; the full set is the day's track.
        Schema::create('salesman_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->dateTime('recorded_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->unsignedTinyInteger('battery')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'salesman_id', 'recorded_at']);
        });

        // Payment collected from a customer in the field. Starts "collected";
        // marked "deposited" once handed in to the office/bank.
        Schema::create('salesman_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('collection_number');
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->enum('mode', ['cash', 'cheque', 'upi', 'bank'])->default('cash');
            $table->string('reference_no')->nullable(); // cheque / txn reference
            $table->date('collection_date');
            $table->enum('status', ['collected', 'deposited', 'bounced', 'cancelled'])->default('collected');
            $table->dateTime('deposited_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'collection_number'], 'salesman_collections_number_unique');
            $table->index(['tenant_id', 'salesman_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Daily field expenses claimed by a salesman (travel, food, fuel, …),
        // with a light approval workflow.
        Schema::create('salesman_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salesman_id')->constrained('salesmen')->cascadeOnDelete();
            $table->date('expense_date');
            $table->string('category')->default('misc'); // travel, food, lodging, fuel, misc
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'salesman_id', 'expense_date']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesman_expenses');
        Schema::dropIfExists('salesman_collections');
        Schema::dropIfExists('salesman_locations');
        Schema::dropIfExists('salesman_visits');
        Schema::dropIfExists('beat_customers');
        Schema::dropIfExists('beats');
        Schema::dropIfExists('salesman_targets');
        Schema::dropIfExists('salesmen');
    }
};
