<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central-DB migration: framework sessions table (SESSION_DRIVER=database).
// Extracted out of the users migration because users moved to tenant scope
// while sessions belong to the central app.
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: on the existing central DB the original users migration
        // already created this table; on a fresh central DB it won't exist.
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
