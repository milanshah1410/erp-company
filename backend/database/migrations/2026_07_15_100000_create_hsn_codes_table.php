<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hsn_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // HSN (goods, 4/6/8 digit) or SAC (services, 6 digit).
            $table->string('code', 8);
            $table->enum('type', ['goods', 'service'])->default('goods');
            $table->string('description');
            // Combined GST rate (%) — CGST+SGST or IGST. Cess is separate.
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('cess_rate', 5, 2)->default(0);
            // Unit Quantity Code (e.g. MTR, PCS, KGS) used on returns / e-invoice.
            $table->string('uqc', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hsn_codes');
    }
};
