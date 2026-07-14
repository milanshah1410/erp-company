<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('visited_at');
            $table->string('purpose')->nullable();          // sales / service / payment-collection / demo / ...
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();  // who visited/logged
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_visits');
    }
};
