<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transport & Logistics module (SRS §12).
 *
 * Drivers & delivery routes are lightweight masters; dispatch plans are the
 * operational document (header + per-consignment items) that schedules a
 * vehicle + driver + route to deliver to customers. Each consignment carries
 * its own Proof-of-Delivery fields (delivered / received-by / photo). Freight
 * & other logistics costs are captured as transport charges, optionally linked
 * back to a dispatch plan. The existing `vehicles` master (shared with the
 * Expenses module) gains transport-relevant compliance-expiry columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Extend the existing vehicle master with transport / compliance fields.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('capacity_kg', 12, 2)->nullable()->after('fuel_type');
            $table->date('insurance_expiry')->nullable()->after('purchase_date');
            $table->date('fitness_expiry')->nullable()->after('insurance_expiry');
            $table->date('permit_expiry')->nullable()->after('fitness_expiry');
            $table->date('puc_expiry')->nullable()->after('permit_expiry');
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('license_no')->nullable();
            $table->string('license_type')->nullable();
            $table->date('license_expiry')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joining_date')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('delivery_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('area')->nullable();
            $table->string('start_point')->nullable();
            $table->string('end_point')->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->decimal('default_charge', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('dispatch_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('dispatch_number');
            $table->date('dispatch_date');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('delivery_routes')->nullOnDelete();
            $table->enum('status', ['draft', 'dispatched', 'delivered', 'cancelled'])->default('draft');
            $table->text('remarks')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'dispatch_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'dispatch_date']);
        });

        Schema::create('dispatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispatch_plan_id')->constrained('dispatch_plans')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->text('delivery_address')->nullable();
            $table->unsignedInteger('packages')->default(1);
            $table->decimal('weight_kg', 12, 2)->nullable();
            $table->decimal('cod_amount', 14, 2)->default(0);
            $table->unsignedInteger('sequence')->default(0);
            // Proof of Delivery
            $table->enum('delivery_status', ['pending', 'delivered', 'failed'])->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->string('received_by')->nullable();
            $table->string('receiver_phone')->nullable();
            $table->string('pod_photo_path')->nullable();
            $table->text('pod_remarks')->nullable();
            $table->timestamps();

            $table->index('delivery_status');
        });

        Schema::create('transport_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('charge_number');
            $table->date('charge_date');
            $table->foreignId('dispatch_plan_id')->nullable()->constrained('dispatch_plans')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('transporter_name')->nullable();
            $table->string('lr_number')->nullable();
            $table->enum('charge_type', ['freight', 'loading', 'unloading', 'toll', 'detention', 'other'])->default('freight');
            $table->enum('payment_mode', ['paid', 'to_pay', 'to_be_billed'])->default('paid');
            $table->enum('payer', ['company', 'customer'])->default('company');
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'charge_number']);
            $table->index(['tenant_id', 'charge_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_charges');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatch_plans');
        Schema::dropIfExists('delivery_routes');
        Schema::dropIfExists('drivers');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['capacity_kg', 'insurance_expiry', 'fitness_expiry', 'permit_expiry', 'puc_expiry']);
        });
    }
};
