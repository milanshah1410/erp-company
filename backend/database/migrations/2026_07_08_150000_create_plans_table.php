<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central-DB migration: subscription plans available to tenants.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();          // starter | growth | enterprise
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0); // monthly price; 0 = custom/contact-sales
            $table->string('billing_cycle')->default('monthly'); // monthly | yearly
            $table->string('currency', 3)->default('INR');
            $table->boolean('is_custom')->default(false); // Enterprise = quote-based
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // { max_users, max_warehouses, max_products, api_access, barcode, reports, integrations, ... }
            $table->json('feature_limits');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
