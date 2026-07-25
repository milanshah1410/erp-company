<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            // `address` doubles as Address Line 1; these extend it for the
            // Google Places autofill (SRS warehouse address block).
            $table->text('address_line2')->nullable()->after('address');
            $table->string('landmark')->nullable()->after('address_line2');
            $table->string('area')->nullable()->after('landmark');
            $table->decimal('latitude', 10, 7)->nullable()->after('pincode');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('google_place_id')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['address_line2', 'landmark', 'area', 'latitude', 'longitude', 'google_place_id']);
        });
    }
};
