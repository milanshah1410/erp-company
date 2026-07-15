<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->string('template')->nullable();          // otp / invoice / dispatch / payment-reminder / festival / manual
            $table->text('body');
            $table->string('provider')->default('log');       // log / msg91
            $table->string('status')->default('queued');      // queued / sent / delivered / failed
            $table->string('provider_message_id')->nullable(); // gateway request/message id
            $table->text('error')->nullable();                // gateway failure detail
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'template']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
