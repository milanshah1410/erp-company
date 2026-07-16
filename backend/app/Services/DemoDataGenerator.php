<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\Broker;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Generates realistic demo records for a tenant (project-stack.md §7).
 *
 * Deliberately faker-free (faker is a dev-only dependency) — sample values come
 * from the pools below so this runs safely in any environment. Must be called
 * inside a TenantContext (the job wraps it) so every row is tenant-stamped.
 *
 * Records are created in chunked transactions via the models, so global scopes,
 * tenant stamping and DB defaults all apply exactly as in normal use.
 */
class DemoDataGenerator
{
    /** Modules this generator knows how to populate → human label. */
    public const MODULES = [
        'customers' => 'Customers',
        'suppliers' => 'Suppliers',
        'products' => 'Products',
        'brokers' => 'Brokers',
        'employees' => 'Employees',
        'expenses' => 'Expenses',
        'leads' => 'Leads',
    ];

    public const COUNTS = [100, 500, 1000, 10000];

    private const CHUNK = 500;

    /** User id stamped as creator on records that require one (e.g. expenses). */
    public function __construct(private ?int $createdBy = null) {}

    private array $first = ['Amit', 'Priya', 'Rahul', 'Sneha', 'Vikram', 'Anjali', 'Rohit', 'Kavya', 'Arjun', 'Divya', 'Karan', 'Meera', 'Nikhil', 'Pooja', 'Sanjay', 'Neha', 'Deepak', 'Ritu', 'Manish', 'Swati'];

    private array $last = ['Sharma', 'Patel', 'Verma', 'Gupta', 'Shah', 'Reddy', 'Nair', 'Iyer', 'Singh', 'Mehta', 'Joshi', 'Desai', 'Rao', 'Kapoor', 'Malhotra', 'Chopra', 'Bose', 'Das', 'Kulkarni', 'Menon'];

    private array $companySuffix = ['Enterprises', 'Trading Co.', 'Industries', 'Exports', 'Textiles', 'Traders', 'Mills', 'Corporation', 'Agencies', 'Distributors'];

    private array $cities = ['Mumbai', 'Delhi', 'Bengaluru', 'Ahmedabad', 'Surat', 'Pune', 'Jaipur', 'Kolkata', 'Chennai', 'Hyderabad', 'Rajkot', 'Indore'];

    private array $states = ['Maharashtra', 'Delhi', 'Karnataka', 'Gujarat', 'Rajasthan', 'West Bengal', 'Tamil Nadu', 'Telangana', 'Madhya Pradesh'];

    private array $productNouns = ['Cotton Shirt', 'Denim Jeans', 'Silk Saree', 'Wool Sweater', 'Linen Kurta', 'Polyester Trousers', 'Cotton Dupatta', 'Formal Blazer', 'Casual T-Shirt', 'Embroidered Blouse', 'Printed Scarf', 'Leather Belt', 'Cotton Towel', 'Bed Sheet', 'Table Cloth'];

    private array $designations = ['Sales Executive', 'Accountant', 'Store Keeper', 'Production Supervisor', 'Tailor', 'Quality Inspector', 'Dispatch Clerk', 'HR Officer', 'Machine Operator', 'Floor Manager'];

    private array $expensePayees = ['MSEB', 'Reliance Jio', 'Local Landlord', 'Courier Partner', 'Fuel Station', 'Office Supplies Vendor', 'Maintenance Contractor', 'Marketing Agency'];

    /**
     * Generate $count records for each requested module.
     *
     * @param  array<int,string>  $modules
     * @return array<string,int>  module => rows created
     */
    public function generate(array $modules, int $count): array
    {
        $count = in_array($count, self::COUNTS, true) ? $count : 100;
        $created = [];

        foreach ($modules as $module) {
            $created[$module] = match ($module) {
                'customers' => $this->make($count, fn (int $i) => $this->customerRow($i), Customer::class),
                'suppliers' => $this->make($count, fn (int $i) => $this->supplierRow($i), Supplier::class),
                'products' => $this->makeProducts($count),
                'brokers' => $this->make($count, fn (int $i) => $this->brokerRow($i), Broker::class),
                'employees' => $this->makeEmployees($count),
                'expenses' => $this->makeExpenses($count),
                'leads' => $this->make($count, fn (int $i) => $this->leadRow($i), Lead::class),
                default => 0,
            };
        }

        return $created;
    }

    /**
     * Insert $count rows built by $rowFn in chunked transactions.
     *
     * @param  callable(int):array  $rowFn
     * @param  class-string  $model
     */
    private function make(int $count, callable $rowFn, string $model): int
    {
        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use ($rowFn, $model, $start, $batch, &$made) {
                for ($i = 0; $i < $batch; $i++) {
                    $model::create($rowFn($start + $i));
                    $made++;
                }
            });
        }

        return $made;
    }

    private function suffix(int $i): string
    {
        // Short, collision-free suffix for unique codes across a run.
        return strtoupper(base_convert((string) (time() % 100000 + $i), 10, 36));
    }

    private function person(int $i): string
    {
        return $this->first[$i % count($this->first)] . ' ' . $this->last[($i * 7) % count($this->last)];
    }

    private function company(int $i): string
    {
        return $this->last[($i * 3) % count($this->last)] . ' ' . $this->companySuffix[$i % count($this->companySuffix)];
    }

    private function phone(int $i): string
    {
        return '9' . str_pad((string) (100000000 + ($i * 137) % 899999999), 9, '0', STR_PAD_LEFT);
    }

    private function customerRow(int $i): array
    {
        return [
            'code' => 'DC-' . $this->suffix($i),
            'name' => $this->person($i),
            'company_name' => $i % 3 === 0 ? $this->company($i) : null,
            'phone' => $this->phone($i),
            'email' => 'customer' . $i . '.' . $this->suffix($i) . '@example.com',
            'type' => $i % 2 === 0 ? 'retail' : 'wholesale',
            'city' => $this->cities[$i % count($this->cities)],
            'state' => $this->states[$i % count($this->states)],
            'country' => 'India',
            'credit_limit' => ($i % 10) * 5000,
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }

    private function supplierRow(int $i): array
    {
        return [
            'code' => 'DS-' . $this->suffix($i),
            'name' => $this->person($i),
            'company_name' => $this->company($i),
            'phone' => $this->phone($i),
            'email' => 'supplier' . $i . '.' . $this->suffix($i) . '@example.com',
            'city' => $this->cities[$i % count($this->cities)],
            'state' => $this->states[$i % count($this->states)],
            'country' => 'India',
            'payment_terms_days' => [0, 15, 30, 45][$i % 4],
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }

    private function brokerRow(int $i): array
    {
        return [
            'code' => 'DB-' . $this->suffix($i),
            'name' => $this->person($i),
            'mobile' => $this->phone($i),
            'city' => $this->cities[$i % count($this->cities)],
            'commission_percent' => [1, 1.5, 2, 2.5, 3][$i % 5],
            'opening_balance' => 0,
            'wallet_balance' => 0,
            'is_active' => true,
        ];
    }

    private function leadRow(int $i): array
    {
        $statuses = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

        return [
            'code' => 'DL-' . $this->suffix($i),
            'name' => $this->person($i),
            'company_name' => $i % 2 === 0 ? $this->company($i) : null,
            'phone' => $this->phone($i),
            'email' => 'lead' . $i . '.' . $this->suffix($i) . '@example.com',
            'source' => ['walk-in', 'referral', 'website', 'cold-call', 'exhibition'][$i % 5],
            'status' => $statuses[$i % count($statuses)],
            'estimated_value' => ($i % 20 + 1) * 2500,
        ];
    }

    private function makeProducts(int $count): int
    {
        $categoryIds = Category::pluck('id')->all() ?: [null];
        $unitIds = Unit::pluck('id')->all() ?: [null];
        $brandIds = Brand::pluck('id')->all() ?: [null];

        return $this->make($count, function (int $i) use ($categoryIds, $unitIds, $brandIds) {
            $cost = ($i % 50 + 5) * 20;

            return [
                'sku' => 'DP-' . $this->suffix($i) . '-' . $i,
                'name' => $this->productNouns[$i % count($this->productNouns)] . ' ' . ($i % 200 + 1),
                'category_id' => $categoryIds[$i % count($categoryIds)],
                'unit_id' => $unitIds[$i % count($unitIds)],
                'brand_id' => $brandIds[$i % count($brandIds)],
                'cost_price' => $cost,
                'selling_price' => round($cost * 1.35, 2),
                'tax_percent' => [0, 5, 12, 18][$i % 4],
                'reorder_level' => 10,
                'reorder_quantity' => 50,
                'is_active' => true,
            ];
        }, Product::class);
    }

    private function makeEmployees(int $count): int
    {
        $deptIds = Department::pluck('id')->all() ?: [null];

        return $this->make($count, function (int $i) use ($deptIds) {
            return [
                'department_id' => $deptIds[$i % count($deptIds)],
                'employee_code' => 'DE-' . $this->suffix($i),
                'first_name' => $this->first[$i % count($this->first)],
                'last_name' => $this->last[($i * 7) % count($this->last)],
                'phone' => $this->phone($i),
                'email' => 'employee' . $i . '.' . $this->suffix($i) . '@example.com',
                'designation' => $this->designations[$i % count($this->designations)],
                'employment_type' => ['full-time', 'part-time', 'contract'][$i % 3],
                'date_of_joining' => now()->subDays(($i % 900) + 30)->toDateString(),
                'is_active' => true,
            ];
        }, Employee::class);
    }

    private function makeExpenses(int $count): int
    {
        $catIds = ExpenseCategory::pluck('id')->all();
        if (empty($catIds)) {
            return 0; // no expense heads seeded → nothing valid to attach to
        }
        $userId = $this->createdBy;
        $methods = ['cash', 'bank', 'upi', 'cheque', 'card'];

        return $this->make($count, function (int $i) use ($catIds, $methods, $userId) {
            $amount = ($i % 100 + 1) * 150;

            return [
                'expense_number' => 'DEMO-EXP-' . $this->suffix($i) . '-' . $i,
                'expense_category_id' => $catIds[$i % count($catIds)],
                'expense_date' => now()->subDays($i % 365)->toDateString(),
                'amount' => $amount,
                'payment_method' => $methods[$i % count($methods)],
                'payee' => $this->expensePayees[$i % count($this->expensePayees)],
                'description' => 'Demo expense entry #' . ($i + 1),
                'status' => 'approved',
                'created_by' => $userId,
                'approved_by' => $userId,
                'approved_at' => now(),
            ];
        }, Expense::class);
    }
}
