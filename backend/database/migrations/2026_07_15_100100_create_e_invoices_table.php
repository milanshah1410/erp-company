<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            // IRP acknowledgement.
            $table->string('irn', 64)->nullable();          // 64-char Invoice Reference Number
            $table->string('ack_no', 30)->nullable();
            $table->dateTime('ack_date')->nullable();
            $table->longText('signed_invoice')->nullable();  // signed JWT from the IRP
            $table->longText('signed_qr_code')->nullable();  // signed QR JWT (rendered as QR on print)
            $table->enum('status', ['pending', 'generated', 'cancelled', 'failed'])->default('pending');
            $table->string('cancel_reason_code')->nullable();
            $table->string('cancel_remarks')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('mode', 20)->default('offline'); // offline | gsp
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_id']);
            $table->unique(['tenant_id', 'irn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoices');
    }
};
