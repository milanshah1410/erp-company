<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the 'production' movement type to stock_ledgers so finished goods
 * received from tailor job-work can be posted to inventory. Only MySQL needs
 * an explicit ALTER; on fresh installs (and the sqlite test DB) the create
 * migration already declares the value, so this is a no-op there.
 */
return new class extends Migration
{
    private const TYPES = [
        'opening', 'purchase', 'sale', 'transfer_in', 'transfer_out',
        'return_in', 'return_out', 'adjustment', 'damage', 'sample', 'consumption',
        'production',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = collect(self::TYPES)->map(fn ($t) => "'{$t}'")->implode(', ');
        DB::statement("ALTER TABLE stock_ledgers MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = collect(self::TYPES)
            ->reject(fn ($t) => $t === 'production')
            ->map(fn ($t) => "'{$t}'")
            ->implode(', ');
        DB::statement("ALTER TABLE stock_ledgers MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }
};
