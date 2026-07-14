<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('description')->nullable();
            $table->string('discount_type')->default('percent');   // percent / flat
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('max_discount', 15, 2)->nullable();      // cap for percent coupons (null = uncapped)
            $table->decimal('min_order_value', 15, 2)->default(0);
            $table->unsignedInteger('usage_limit')->nullable();      // total redemptions allowed (null = unlimited)
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
