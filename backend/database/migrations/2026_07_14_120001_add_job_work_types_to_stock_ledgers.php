<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the 'job_work_out' / 'job_work_in' movement types to stock_ledgers so
 * material issued to an outside job-work vendor and finished goods received
 * back can be posted to inventory. Only MySQL needs an explicit ALTER; on
 * fresh installs (and the sqlite test DB) the create migration already
 * declares the values, so this is a no-op there.
 */
return new class extends Migration
{
    private const TYPES = [
        'opening', 'purchase', 'sale', 'transfer_in', 'transfer_out',
        'return_in', 'return_out', 'adjustment', 'damage', 'sample', 'consumption',
        'production', 'job_work_out', 'job_work_in',
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
            ->reject(fn ($t) => in_array($t, ['job_work_out', 'job_work_in'], true))
            ->map(fn ($t) => "'{$t}'")
            ->implode(', ');
        DB::statement("ALTER TABLE stock_ledgers MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }
};
