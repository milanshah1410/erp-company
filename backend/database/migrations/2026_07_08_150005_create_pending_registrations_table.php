<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central-DB migration: holds a self-serve signup from submission through
// email verification and tenant provisioning. Once provisioned it points at
// the created tenant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('admin_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('password'); // hashed
            $table->string('plan_slug')->default('starter');
            $table->string('verification_code', 6)->nullable();
            $table->string('verification_token')->nullable();
            $table->timestamp('verified_at')->nullable();
            // pending_verification | provisioning | active | failed
            $table->string('status')->default('pending_verification')->index();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
