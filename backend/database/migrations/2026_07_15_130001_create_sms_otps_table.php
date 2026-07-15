<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time passwords sent over SMS. Deliberately NOT tenant-scoped at the query
 * level: the portal login OTP flow runs before any tenant context exists (the
 * customer is resolved cross-tenant by email, exactly like password login), so
 * the tenant_id is stored for reference/audit but verification matches on
 * phone + purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->string('purpose')->default('portal_login'); // portal_login / phone_verification / ...
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_otps');
    }
};
