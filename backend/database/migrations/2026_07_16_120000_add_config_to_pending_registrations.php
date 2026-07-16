<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central-DB migration: the onboarding wizard collects far more than the
// original signup (industry, modules, extra users, branch/warehouse, financial
// year, currency, number series, tax). It is stashed here as a JSON blob and
// consumed by App\Jobs\ProvisionTenant once the email is verified.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->json('config')->nullable()->after('plan_slug');
        });
    }

    public function down(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
