<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered handsets, for Firebase push (retail base §10).
 *
 * A device belongs to a *user*, not to a tenant alone: a push is "tell Rakesh",
 * and Rakesh has a phone. The tenant column is still here because everything in
 * this database has one and the scope depends on it, but the meaningful key is
 * (user, token).
 *
 * Tokens are FCM registration tokens: the string the Firebase SDK hands the app
 * after it registers. They rotate — on reinstall, on restore to a new handset,
 * and occasionally for no visible reason — so this table is written every time
 * the app starts rather than once at sign-up, and rows are pruned when FCM
 * reports a token dead. That is why `is_active` and `last_failed_at` exist: a
 * token that FCM has rejected as UNREGISTERED is switched off rather than
 * deleted, so a support conversation can still see that the phone was once
 * registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // cascadeOnDelete: a token with no user is a message with no recipient.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /**
             * FCM registration tokens are long (150+ chars today and not
             * contractually bounded), so this is a text column with a prefix
             * index rather than a string(255) that would silently truncate the
             * one value the row exists to hold.
             */
            $table->text('token');
            $table->string('token_hash', 64);

            $table->enum('platform', ['android', 'ios', 'web'])->default('android');
            // What the user sees in "your devices" — "Redmi Note 12", not a token.
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 30)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            // Set when FCM rejects the token. Kept so a dead handset is visible
            // rather than simply absent.
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_error', 255)->nullable();

            $table->timestamps();

            /**
             * Uniqueness is on the hash, not the token: MySQL cannot build a
             * unique index over a TEXT column without a prefix length, and a
             * prefix could collide across two genuinely different tokens. The
             * hash is a plain sha256 of the token — it is not a secret being
             * protected, just a fixed-width key.
             *
             * Globally unique rather than per-tenant, because a handset that
             * moves between workspaces must not end up registered twice and
             * receive both shops' notifications.
             */
            $table->unique('token_hash');
            $table->index(['tenant_id', 'user_id', 'is_active'], 'push_devices_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
