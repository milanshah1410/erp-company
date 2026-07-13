<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gst_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // A GSTIN is state-specific; a branch in another state carries its own.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gstin', 15);
            $table->string('legal_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('state_code', 2);  // first two digits of the GSTIN
            $table->string('state')->nullable();
            $table->enum('registration_type', [
                'regular', 'composition', 'casual', 'sez',
                'input_service_distributor', 'tds', 'tcs', 'unregistered',
            ])->default('regular');
            $table->text('address')->nullable();
            $table->date('effective_from')->nullable();
            // The default registration used on documents when none is specified.
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'gstin']);
            $table->index(['tenant_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_registrations');
    }
};
