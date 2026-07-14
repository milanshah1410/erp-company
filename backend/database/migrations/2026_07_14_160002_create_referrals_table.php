<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The existing customer who made the referral.
            $table->foreignId('referrer_customer_id')->constrained('customers')->cascadeOnDelete();
            // The referred person; becomes a customer once they join.
            $table->string('referred_name');
            $table->string('referred_phone')->nullable();
            $table->foreignId('referred_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status')->default('pending');   // pending / joined / rewarded / cancelled
            $table->integer('reward_points')->default(0);    // loyalty points granted to the referrer on reward
            $table->text('notes')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
