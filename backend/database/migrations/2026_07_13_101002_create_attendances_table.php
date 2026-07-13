<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('status', ['present', 'absent', 'half-day', 'leave', 'holiday', 'week-off'])->default('present');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            // One attendance row per employee per day.
            $table->unique(['tenant_id', 'employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
