<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for write endpoints (mobile app §18 "Offline Sync").
 *
 * A phone at a shop counter loses signal mid-request more often than anything
 * else in this system does. The client cannot tell "the bill never reached the
 * server" from "the bill posted and the reply was lost", so its only safe move
 * is to retry — and a retried `POST /api/pos/checkout` posts the bill twice.
 *
 * The fix is for the *client* to name the attempt: it mints one key per business
 * intent (one bill, one payment, one adjustment) and re-sends that same key on
 * every retry. The first request to arrive with a given key does the work and
 * has its reply stored here; every later arrival is answered from this table
 * without touching the ledger again.
 *
 * Three columns carry the whole design:
 *
 *  - `key` is the client's name for the attempt, unique per tenant.
 *  - `request_hash` fingerprints what was actually sent. A key that comes back
 *    with a *different* body is a client bug (a reused key), not a retry, and is
 *    refused rather than silently answered with the wrong bill's response.
 *  - `status` distinguishes "still running" from "finished". Two retries racing
 *    each other must not both execute; the loser gets told to wait, not served a
 *    half-written response.
 *
 * Rows are pruned after `expires_at` (see `idempotency:prune`). The window only
 * has to outlive a client's retry loop, not the record — the invoice itself is
 * the permanent record, this is just the receipt for having created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Who posted it. Nullable so a row survives the user being deleted:
            // the point of the row is to not repeat the write, and that stays
            // true after the person who made it has gone.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('key', 128);
            /**
             * sha256 of method + path + body. Not a secret — a fingerprint, so
             * that a key arriving with different content is caught instead of
             * being answered from the wrong request's stored reply.
             */
            $table->string('request_hash', 64);

            $table->string('method', 10);
            $table->string('path', 255);

            // in_progress → the first request is still running (a racing retry
            // is told to wait). completed → response_* below is replayable.
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');

            $table->unsignedSmallInteger('response_status')->nullable();
            // Only JSON bodies are stored, and only for successful writes; see
            // App\Http\Middleware\EnsureIdempotency.
            $table->longText('response_body')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();

            $table->timestamps();

            /**
             * The lock. Scoped per tenant rather than globally: two workspaces
             * minting the same UUID is astronomically unlikely but it would be a
             * cross-tenant data leak if it ever happened, and a composite unique
             * index costs nothing to prevent it.
             *
             * This index is also the concurrency primitive — the first insert to
             * land wins, and the loser's duplicate-key error is what tells a
             * racing retry that somebody else got there first.
             */
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
