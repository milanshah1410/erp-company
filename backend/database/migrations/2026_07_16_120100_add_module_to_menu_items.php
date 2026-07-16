<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tag menu rows with the onboarding "module" they belong to (crm, sales,
// purchase, inventory, manufacturing, accounts, hrms, pos, broker). The
// MenuController hides a tagged row when the tenant's enabled_modules setting
// (chosen in the onboarding wizard) does not include it. NULL = always shown.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('module')->nullable()->after('feature')->index();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('module');
        });
    }
};
