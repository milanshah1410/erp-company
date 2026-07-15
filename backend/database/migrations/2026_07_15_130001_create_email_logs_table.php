<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('mailable');                            // invoice / purchase_order / quotation / campaign / test
            $table->nullableMorphs('related');                     // Invoice / PurchaseOrder / SalesQuotation
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->text('cc')->nullable();                        // comma-separated
            $table->string('status')->default('queued');           // queued / sent / failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'mailable']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
