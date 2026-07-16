<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\RowsImport;
use App\Models\Brand;
use App\Models\Broker;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Generic per-module data import (project-stack.md §8): Excel / CSV / JSON in,
 * validated records out. Each module declares its importable columns, required
 * fields, and any name→id lookups (e.g. a product's category by name).
 */
class DataImportService
{
    /**
     * Module registry.
     *   fields   – plain columns copied straight from the row
     *   required – fields that must be present & non-empty
     *   resolve  – csvColumn => [relatedModel, matchColumn, targetForeignKey]
     *   autocode – [field, prefix] auto-filled with a unique code when blank
     */
    private const MODULES = [
        'customers' => [
            'model' => Customer::class,
            'label' => 'Customers',
            'fields' => ['code', 'name', 'company_name', 'email', 'phone', 'type', 'gst_number', 'city', 'state', 'country', 'pincode', 'credit_limit', 'opening_balance'],
            'required' => ['name'],
            'resolve' => [],
            'autocode' => ['code', 'CUST'],
        ],
        'suppliers' => [
            'model' => Supplier::class,
            'label' => 'Suppliers',
            'fields' => ['code', 'name', 'company_name', 'email', 'phone', 'gst_number', 'city', 'state', 'country', 'pincode', 'payment_terms_days', 'opening_balance'],
            'required' => ['name'],
            'resolve' => [],
            'autocode' => ['code', 'SUP'],
        ],
        'products' => [
            'model' => Product::class,
            'label' => 'Products',
            'fields' => ['sku', 'barcode', 'name', 'description', 'hsn_code', 'cost_price', 'selling_price', 'tax_percent', 'reorder_level', 'reorder_quantity'],
            'required' => ['name'],
            'resolve' => [
                'category' => [Category::class, 'name', 'category_id'],
                'brand' => [Brand::class, 'name', 'brand_id'],
                'unit' => [Unit::class, 'short_name', 'unit_id'],
            ],
            'autocode' => ['sku', 'SKU'],
        ],
        'brokers' => [
            'model' => Broker::class,
            'label' => 'Brokers',
            'fields' => ['code', 'name', 'mobile', 'gst_number', 'pan_number', 'commission_percent', 'fixed_commission', 'tds_percent', 'opening_balance'],
            'required' => ['name'],
            'resolve' => [],
            'autocode' => ['code', 'BRK'],
        ],
        'employees' => [
            'model' => Employee::class,
            'label' => 'Employees',
            'fields' => ['employee_code', 'first_name', 'last_name', 'email', 'phone', 'designation', 'employment_type', 'date_of_joining', 'gender'],
            'required' => ['first_name'],
            'resolve' => [
                'department' => [Department::class, 'name', 'department_id'],
            ],
            'autocode' => ['employee_code', 'EMP'],
        ],
        'warehouses' => [
            'model' => Warehouse::class,
            'label' => 'Warehouses',
            'fields' => ['code', 'name', 'type', 'address', 'city', 'state', 'pincode'],
            'required' => ['name'],
            'resolve' => [],
            'autocode' => ['code', 'WH'],
        ],
        'expenses' => [
            'model' => Expense::class,
            'label' => 'Expenses',
            'fields' => ['expense_number', 'expense_date', 'amount', 'payment_method', 'payee', 'reference_no', 'description'],
            'required' => ['amount'],
            'resolve' => [
                'expense_category' => [ExpenseCategory::class, 'name', 'expense_category_id'],
            ],
            'autocode' => ['expense_number', 'EXP'],
        ],
    ];

    /** @return array<int,array{key:string,label:string}> */
    public static function modules(): array
    {
        return collect(self::MODULES)
            ->map(fn ($cfg, $key) => ['key' => $key, 'label' => $cfg['label']])
            ->values()
            ->all();
    }

    public static function isModule(string $module): bool
    {
        return isset(self::MODULES[$module]);
    }

    /**
     * The column headers for a module's import template (plain fields + the
     * human-friendly lookup columns).
     *
     * @return array<int,string>
     */
    public static function templateColumns(string $module): array
    {
        $cfg = self::MODULES[$module];

        return array_values(array_merge($cfg['fields'], array_keys($cfg['resolve'])));
    }

    /**
     * Parse an uploaded Excel / CSV / JSON file into heading-keyed rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function parse(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'json') {
            $decoded = json_decode((string) file_get_contents($file->getRealPath()), true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        // xlsx / xls / csv — heading row mapped to snake_case keys.
        $import = new RowsImport();
        Excel::import($import, $file);

        return $import->rows;
    }

    /**
     * Import already-parsed rows into a module.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{created:int,failed:int,errors:array<int,array{row:int,message:string}>}
     */
    public function import(string $module, array $rows): array
    {
        $cfg = self::MODULES[$module];
        $model = $cfg['model'];

        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $raw) {
            $rowNo = $index + 2; // header is row 1
            $row = $this->normalizeKeys($raw);

            try {
                $attrs = $this->buildAttributes($cfg, $row);

                foreach ($cfg['required'] as $req) {
                    if (! isset($attrs[$req]) || $attrs[$req] === '') {
                        throw new \RuntimeException("Missing required field '{$req}'.");
                    }
                }

                $model::create($attrs);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < 50) {
                    $errors[] = ['row' => $rowNo, 'message' => $this->cleanMessage($e->getMessage())];
                }
            }
        }

        return ['created' => $created, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Map a normalized row onto model attributes: copy known fields, resolve
     * name→id lookups.
     *
     * @param  array<string,mixed>  $cfg
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function buildAttributes(array $cfg, array $row): array
    {
        $attrs = [];

        foreach ($cfg['fields'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $attrs[$field] = $row[$field];
            }
        }

        foreach ($cfg['resolve'] as $col => [$related, $matchCol, $fk]) {
            $value = $row[$col] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $id = $related::where($matchCol, $value)->value('id');
            if ($id) {
                $attrs[$fk] = $id;
            } else {
                throw new \RuntimeException("Unknown {$col}: '{$value}'.");
            }
        }

        // Auto-generate a unique code/number when the file leaves it blank.
        if (isset($cfg['autocode'])) {
            [$field, $prefix] = $cfg['autocode'];
            if (empty($attrs[$field])) {
                $attrs[$field] = $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
            }
        }

        return $attrs;
    }

    /**
     * Normalize header keys to snake_case so JSON (arbitrary case/spaces) lines
     * up with the maatwebsite heading behaviour used for Excel/CSV.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $norm = strtolower(trim((string) $key));
            $norm = preg_replace('/[^a-z0-9]+/', '_', $norm) ?? $norm;
            $out[trim($norm, '_')] = is_string($value) ? trim($value) : $value;
        }

        return $out;
    }

    private function cleanMessage(string $message): string
    {
        // Collapse a verbose SQL duplicate-key error into something readable.
        if (str_contains($message, 'Duplicate entry')) {
            return 'Duplicate entry — a record with this unique value already exists.';
        }

        return mb_substr($message, 0, 160);
    }
}
