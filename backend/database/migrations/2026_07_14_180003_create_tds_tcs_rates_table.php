<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDS / TCS section-rate master. One row per statutory section (194C, 194J,
 * 206C(1H), ...) with its applicable rate and threshold. Feeds the section
 * pickers on purchase/expense (TDS) and sales (TCS) entry forms, and labels the
 * TDS/TCS registers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tds_tcs_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('section');
            $table->enum('nature', ['tds', 'tcs']);
            $table->decimal('rate', 5, 2)->default(0);
            $table->decimal('threshold', 15, 2)->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'section']);
            $table->index(['tenant_id', 'nature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_tcs_rates');
    }
};
