<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            // Nullable: pre-existing warehouses and tenant-wide warehouses stay
            // unassigned. Set null (not cascade) so removing a company/branch
            // does not silently delete its stock-bearing warehouses.
            $table->foreignId('company_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
