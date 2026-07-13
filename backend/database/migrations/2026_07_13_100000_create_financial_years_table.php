<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "FY 2025-26"
            $table->date('start_date');
            $table->date('end_date');
            // A closed year is locked: no back-dated postings allowed in its period.
            $table->boolean('is_closed')->default(false);
            // The single working year for the company; transactions default to it.
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'company_id', 'name']);
            $table->index(['tenant_id', 'company_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_years');
    }
};
