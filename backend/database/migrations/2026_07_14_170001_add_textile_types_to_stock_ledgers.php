<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the 'fabric_roll_in' / 'fabric_cut_out' movement types to stock_ledgers
 * so fabric received as rolls and length drawn off a roll during cutting can be
 * posted to inventory. Only MySQL needs an explicit ALTER; on fresh installs
 * (and the sqlite test DB) the base create migration declares a string/enum
 * that already accepts these values.
 */
return new class extends Migration
{
    private const TYPES = [
        'opening', 'purchase', 'sale', 'transfer_in', 'transfer_out',
        'return_in', 'return_out', 'adjustment', 'damage', 'sample', 'consumption',
        'production', 'job_work_out', 'job_work_in',
        'fabric_roll_in', 'fabric_cut_out',
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
            ->reject(fn ($t) => in_array($t, ['fabric_roll_in', 'fabric_cut_out'], true))
            ->map(fn ($t) => "'{$t}'")
            ->implode(', ');
        DB::statement("ALTER TABLE stock_ledgers MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }
};
