<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->date('anniversary_date')->nullable()->after('date_of_birth');
            // Referral: which existing customer referred this one.
            $table->foreignId('referred_by_customer_id')->nullable()->after('anniversary_date')
                ->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_customer_id');
            $table->dropColumn(['date_of_birth', 'anniversary_date']);
        });
    }
};
