<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support & Customer Success (SaaS-owner platform panel): support tickets an
 * operator raises/tracks on behalf of a tenant, plus their threaded messages.
 * Central tables — tickets describe the platform↔tenant relationship, so
 * tenant_id is a plain indexed column (no FK: the tenants table may live on a
 * different connection in a split-DB deployment), and the author/assignee FKs
 * point at central_users (platform operators).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('subject');
            $table->string('category', 40)->default('general'); // general|billing|technical|onboarding|data
            $table->string('status', 20)->default('open');      // open|pending|resolved|closed
            $table->string('priority', 20)->default('normal');  // low|normal|high|urgent
            $table->string('contact_name')->nullable();  // tenant-side person the thread is with
            $table->string('contact_email')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('central_users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('central_users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable(); // last message/status change, drives triage sort
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 20)->default('operator'); // operator|tenant|system
            $table->string('author_name');
            $table->foreignId('central_user_id')->nullable()->constrained('central_users')->nullOnDelete();
            $table->boolean('is_internal')->default(false); // internal note vs. customer-visible reply
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
