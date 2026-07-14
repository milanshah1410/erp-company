<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RFID EPC / tag identifier alongside the existing barcode. Nullable and
     * unique per tenant (MySQL permits multiple NULLs in a unique index, so
     * untagged items coexist). Handheld RFID readers emulate keyboard/HID input
     * like barcode scanners, so a scanned tag resolves through barcode/lookup.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('rfid_tag')->nullable()->after('barcode');
            $table->unique(['tenant_id', 'rfid_tag']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('rfid_tag')->nullable()->after('barcode');
            $table->unique(['tenant_id', 'rfid_tag']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'rfid_tag']);
            $table->dropColumn('rfid_tag');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'rfid_tag']);
            $table->dropColumn('rfid_tag');
        });
    }
};
