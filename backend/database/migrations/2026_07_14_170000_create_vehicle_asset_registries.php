<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle & Asset master registries.
 *
 * Lightweight masters the Expenses sub-modules reference: vehicle expense /
 * fuel expense link to a vehicle; factory / asset maintenance link to an
 * asset. Keeping them relational (rather than free text) enables per-vehicle
 * mileage and per-asset service-cost history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('registration_no');
            $table->string('name')->nullable();
            $table->enum('type', ['car', 'bike', 'truck', 'tempo', 'van', 'other'])->default('other');
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->enum('fuel_type', ['petrol', 'diesel', 'cng', 'electric', 'other'])->nullable();
            $table->date('purchase_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'registration_no']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('asset_code');
            $table->string('name');
            $table->enum('category', ['machine', 'equipment', 'furniture', 'electronic', 'vehicle', 'building', 'other'])->default('other');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('location')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'asset_code']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('vehicles');
    }
};
