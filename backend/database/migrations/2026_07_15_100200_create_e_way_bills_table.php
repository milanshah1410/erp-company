<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_way_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('ewb_no', 20)->nullable();        // 12-digit EWB number
            $table->dateTime('ewb_date')->nullable();
            $table->dateTime('valid_upto')->nullable();
            // Transport details.
            $table->enum('transport_mode', ['road', 'rail', 'air', 'ship'])->default('road');
            $table->string('transporter_id', 20)->nullable(); // transporter GالسTIN / TRANSIN
            $table->string('transporter_name')->nullable();
            $table->string('transport_doc_no')->nullable();
            $table->date('transport_doc_date')->nullable();
            $table->string('vehicle_no', 20)->nullable();
            $table->enum('vehicle_type', ['regular', 'over_dimensional_cargo'])->default('regular');
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->string('from_place')->nullable();
            $table->string('from_pincode', 6)->nullable();
            $table->string('to_place')->nullable();
            $table->string('to_pincode', 6)->nullable();
            $table->enum('status', ['pending', 'generated', 'cancelled', 'failed'])->default('pending');
            $table->string('cancel_reason_code')->nullable();
            $table->string('cancel_remarks')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('mode', 20)->default('offline');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'ewb_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_way_bills');
    }
};
