<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central-DB migration: server-side progress for the onboarding automation
// (Volume 11 §"Onboarding Automation"). ProvisionTenant records one entry per
// documented phase as it runs, so the operator panel and the self-serve
// provisioning screen can show what actually happened instead of a
// client-side guess. See App\Support\ProvisionSteps for the shape.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->json('provision_steps')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn('provision_steps');
        });
    }
};
