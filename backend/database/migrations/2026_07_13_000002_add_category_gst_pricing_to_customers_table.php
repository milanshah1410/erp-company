<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('customer_category_id')->nullable()->after('type')
                ->constrained('customer_categories')->nullOnDelete();

            // Structured GST details (gst_number already exists).
            $table->enum('gst_registration_type', [
                'regular', 'composition', 'unregistered', 'consumer', 'overseas',
            ])->default('unregistered')->after('gst_number');
            $table->string('pan', 10)->nullable()->after('gst_registration_type');
            $table->string('place_of_supply')->nullable()->after('pan');

            // Default pricing tier for this customer (falls back to the category's tier).
            $table->enum('default_price_level', ['mrp', 'wholesale', 'retail', 'discount'])
                ->nullable()->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_category_id');
            $table->dropColumn([
                'gst_registration_type', 'pan', 'place_of_supply', 'default_price_level',
            ]);
        });
    }
};
