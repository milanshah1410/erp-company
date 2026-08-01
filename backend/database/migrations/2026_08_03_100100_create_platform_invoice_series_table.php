<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central-DB migration: the subscription tax-invoice number series.
 *
 * A GST tax invoice needs a number that is unique, gap-free and consecutive
 * within the financial year, which rules out deriving it from the row id (rows
 * can be deleted, and the series restarts every April). One row per financial
 * year holds the next number; App\Services\Billing\InvoiceNumberService takes a
 * row lock while it increments, so two simultaneous renewals cannot be handed
 * the same number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoice_series', function (Blueprint $table) {
            $table->id();
            // Indian financial year the series belongs to: '2026-27'.
            $table->string('financial_year', 9)->unique();
            // Snapshot of the configured prefix at the time the series opened, so
            // renaming the prefix later cannot renumber invoices already issued.
            $table->string('prefix', 32);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_series');
    }
};
