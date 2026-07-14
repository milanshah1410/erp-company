<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->string('template')->nullable();          // invoice / payment-reminder / birthday / promo / manual
            $table->text('body');
            $table->string('channel')->default('click_to_chat'); // click_to_chat / api
            $table->string('status')->default('queued');     // queued / sent / delivered / failed
            $table->text('link')->nullable();                // wa.me deep link (for click-to-chat)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
