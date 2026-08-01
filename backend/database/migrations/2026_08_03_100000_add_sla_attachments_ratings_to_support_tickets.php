<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support & Customer Success, second pass (Volume 11 §"Support Tickets" →
 * SLA / Attachments / Ratings).
 *
 *  - **SLA**: the tier is snapshotted onto the ticket at creation together with
 *    the two clocks it implies. Storing the resolved due-dates (rather than
 *    recomputing from the plan on read) means a mid-thread plan change or a
 *    config edit can never move the goalposts on a ticket already in flight.
 *  - **Attachments**: files hang off the ticket, optionally pinned to the
 *    message they arrived with, so the thread can render them inline.
 *  - **Ratings**: one CSAT score per ticket, captured once it is resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // SLA — tier snapshot + the two clocks it sets.
            $table->string('sla_tier', 20)->default('standard')->after('priority');
            $table->timestamp('response_due_at')->nullable()->after('sla_tier');
            $table->timestamp('resolution_due_at')->nullable()->after('response_due_at');
            $table->timestamp('first_response_at')->nullable()->after('resolution_due_at');

            // CSAT.
            $table->unsignedTinyInteger('rating')->nullable()->after('closed_at'); // 1..5
            $table->string('rating_comment', 1000)->nullable()->after('rating');
            $table->timestamp('rated_at')->nullable()->after('rating_comment');

            // Breach sweeps scan open tickets by due date.
            $table->index(['status', 'resolution_due_at']);
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            // Null for files attached to the ticket itself rather than a reply.
            $table->foreignId('support_ticket_message_id')->nullable()
                ->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('central_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'resolution_due_at']);
            $table->dropColumn([
                'sla_tier', 'response_due_at', 'resolution_due_at', 'first_response_at',
                'rating', 'rating_comment', 'rated_at',
            ]);
        });
    }
};
