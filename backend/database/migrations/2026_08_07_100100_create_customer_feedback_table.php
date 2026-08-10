<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer feedback & CSAT on the tenant side (retail base §6).
 *
 * CSAT already existed, but only for *platform* support tickets — the SaaS
 * owner's measure of its own helpdesk. This is the shop's measure of itself,
 * which is a different record with a different owner: it hangs off a bill or a
 * repair, it is answered by a customer rather than by an operator, and the
 * number it produces is the shop's to act on.
 *
 * One table, two ways in, and that is the design decision worth stating:
 *
 *  1. **Asked for.** Staff send a link after a bill or a job — a row is created
 *     `pending` with a token, and the customer fills it in later. `requested_at`
 *     is stamped, `submitted_at` is not.
 *  2. **Written down.** Somebody says something at the counter and the counter
 *     records it. The row is born `submitted`, with no token at all.
 *
 * Both live here because the CSAT score has to count both. A shop whose only
 * measured feedback is the link it remembered to send is measuring its own
 * diligence, not its customers.
 *
 * Deliberately NOT a survey builder. One score, one comment, an optional set of
 * tags, and a reply — because the question a shop actually needs answered is
 * "was that all right?", and every field past that one is a field nobody fills
 * in on a phone in a car park.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /**
             * Nullable, and nullOnDelete: a walk-in leaves feedback without ever
             * becoming a customer record, and erasing a customer must not erase
             * what they told the shop. The name/phone below are what survives.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Kept alongside the FK rather than derived from it, for exactly the
            // case above — and because a customer who renames does not retro-
            // actively change who left a review two years ago.
            $table->string('customer_name', 180)->nullable();
            $table->string('customer_phone', 30)->nullable();

            /**
             * What the feedback is about. Both nullable — a shop can also be told
             * something general ("your parking is impossible") that belongs to no
             * document at all, and refusing to record that would be refusing the
             * most useful thing anybody says.
             */
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();

            /**
             * How it reached the shop. `counter` means a member of staff typed it
             * in; everything else means the customer did. Worth separating on
             * the report: staff-recorded feedback skews kind, and a shop reading
             * one blended average would not know that.
             */
            $table->enum('channel', ['counter', 'link', 'sms', 'whatsapp', 'email', 'portal'])->default('counter');

            /**
             * 1–5. Not 1–10 and not a percentage: a five-point scale is what a
             * customer answers on a phone without thinking about it, and the CSAT
             * convention (4 and 5 are satisfied) is built on exactly this shape.
             * Nullable while a request is still outstanding.
             */
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            /**
             * What it was about, as a short list the shop defines for itself —
             * "staff", "price", "wait", "quality". JSON rather than a join table
             * because nothing joins on them: they are read as chips on a card and
             * counted in one pass over the register.
             */
            $table->json('tags')->nullable();

            /**
             * pending → submitted → (acknowledged | resolved). Acknowledgement is
             * its own state because "we have read this" and "we have done
             * something about it" are different promises, and a complaints book
             * that cannot tell them apart lets the second one quietly not happen.
             */
            $table->enum('status', ['pending', 'submitted', 'acknowledged', 'resolved'])->default('pending');

            /**
             * The public link. Random and unguessable rather than signed, so it
             * survives being forwarded, pasted into WhatsApp and opened a week
             * later — a signature with an expiry is a review nobody can leave.
             * Null for feedback recorded at the counter, which has no link.
             */
            $table->string('token', 64)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            // The shop's reply, and who made it.
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /**
             * Globally unique, not per-tenant: the public endpoint resolves a
             * token *before* any tenant is known (the tenant is derived from the
             * row), so two tenants sharing a token would be ambiguous at exactly
             * the moment it matters. Tokens are random enough that this costs
             * nothing.
             */
            $table->unique('token');
            // The register as a worklist: what is unanswered, newest first.
            $table->index(['tenant_id', 'status', 'created_at'], 'customer_feedback_status_idx');
            // "How did we do on this bill?" — asked from the invoice screen.
            $table->index(['tenant_id', 'invoice_id'], 'customer_feedback_invoice_idx');
            $table->index(['tenant_id', 'customer_id'], 'customer_feedback_customer_idx');
            // The score, over a period. Filtered on submitted rows only.
            $table->index(['tenant_id', 'rating', 'submitted_at'], 'customer_feedback_score_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_feedback');
    }
};
