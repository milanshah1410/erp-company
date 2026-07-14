<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->morphs('followupable');                 // Lead or Customer (followupable_type + _id)
            $table->string('type')->default('call');        // call / visit / email / whatsapp / other
            $table->timestamp('due_at');
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending');    // pending / done / cancelled
            $table->timestamp('completed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
