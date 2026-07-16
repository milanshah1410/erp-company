<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite (tenant_id, ...) indexes for the hottest transactional tables
 * (§17 Performance — Indexes).
 *
 * Every tenant-owned query is filtered by tenant_id first (the row-level
 * TenantScope), then commonly narrowed by status or a date and sorted by date.
 * A single-column status/date index can't serve `WHERE tenant_id = ? AND
 * status = ?`; a leading-tenant composite can. foreignId()->constrained()
 * already gives each table a single tenant_id index, so this only adds the
 * multi-column ones that the list/report/ledger endpoints actually range over.
 *
 * Defensive by design: an index is created only when its table and every one
 * of its columns exist and no index already covers exactly those columns, so
 * the migration is idempotent and safe to run against a partially-diverged
 * schema (or the SQLite test database) without erroring.
 */
return new class extends Migration
{
    /**
     * table => list of column-sets to index. Column names verified against the
     * create migrations; anything that doesn't match is skipped, not fatal.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private array $indexes = [
        'sales_orders' => [['tenant_id', 'status'], ['tenant_id', 'order_date'], ['tenant_id', 'customer_id']],
        'invoices' => [['tenant_id', 'status'], ['tenant_id', 'invoice_date']],
        'payments' => [['tenant_id', 'payment_date'], ['tenant_id', 'invoice_id']],
        'purchase_orders' => [['tenant_id', 'status'], ['tenant_id', 'order_date'], ['tenant_id', 'supplier_id']],
        'purchase_invoices' => [['tenant_id', 'status'], ['tenant_id', 'invoice_date']],
        'sales_returns' => [['tenant_id', 'status'], ['tenant_id', 'return_date']],
        'sales_quotations' => [['tenant_id', 'status'], ['tenant_id', 'quotation_date']],
        'expenses' => [['tenant_id', 'status'], ['tenant_id', 'expense_date']],
        'leads' => [['tenant_id', 'status']],
        'stock_transfers' => [['tenant_id', 'status']],
        'stock_adjustments' => [['tenant_id', 'status']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columnSets) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getIndexes($table))
                ->map(fn ($i) => implode(',', $i['columns']))
                ->all();

            foreach ($columnSets as $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    continue;
                }
                if (in_array(implode(',', $columns), $existing, true)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $t) use ($columns) {
                    $t->index($columns);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columnSets) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $names = collect(Schema::getIndexes($table))->pluck('name')->all();

            foreach ($columnSets as $columns) {
                // Only drop the conventionally-named index this migration would
                // have created; leave any pre-existing index of its own name be.
                $name = $table.'_'.implode('_', $columns).'_index';
                if (! in_array($name, $names, true)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $t) use ($name) {
                    $t->dropIndex($name);
                });
            }
        }
    }
};
