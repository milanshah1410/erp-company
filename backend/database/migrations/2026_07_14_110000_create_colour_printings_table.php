<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colour Printing module.
 *
 * A colour-printing job sent to an external printer (a supplier / vendor).
 * Screen printing is priced by the number of screens and colours involved;
 * each row tracks the print type, screen/colour counts, the agreed cost and
 * where the job is in its lifecycle (pending -> in progress -> completed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colour_printings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_vendor_id')->constrained('suppliers')->cascadeOnDelete();
            $table->enum('print_type', ['screen', 'digital', 'sublimation', 'dtf', 'vinyl', 'block', 'offset'])->default('screen');
            $table->unsignedInteger('screen_count')->default(0);
            $table->unsignedInteger('colour_count')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'printer_vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colour_printings');
    }
};
