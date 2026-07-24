<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes WHO suspended a tenant so enforcement can differ:
 * 'billing' (lapsed subscription) keeps the read-only grace period while
 * 'operator' (SaaS-admin action) hard-locks the workspace entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // operator | billing — null while the tenant is not suspended
            $table->string('suspended_by')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('suspended_by');
        });
    }
};
