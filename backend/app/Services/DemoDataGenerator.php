<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Attendance;
use App\Models\BankAccount;
use App\Models\Batch;
use App\Models\Beat;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\Brand;
use App\Models\Broker;
use App\Models\Category;
use App\Models\ChannelIntegration;
use App\Models\ChannelListing;
use App\Models\ChannelOrder;
use App\Models\ChannelSyncLog;
use App\Models\ColourPrinting;
use App\Models\Contractor;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\DeliveryRoute;
use App\Models\Department;
use App\Models\Driver;
use App\Models\ElectricityBill;
use App\Models\EmailLog;
use App\Models\EmbroideryPayment;
use App\Models\EmbroideryVendor;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeeClaim;
use App\Models\EmployeeLoan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FabricRoll;
use App\Models\FactoryMaintenance;
use App\Models\FollowUp;
use App\Models\FuelExpense;
use App\Models\GateEntry;
use App\Models\GstRegistration;
use App\Models\HsnCode;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\MarketingCampaign;
use App\Models\OvertimeRecord;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\RateCard;
use App\Models\Referral;
use App\Models\Salary;
use App\Models\SalaryAdvance;
use App\Models\SalaryStructure;
use App\Models\Salesman;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesReturn;
use App\Models\SerialNumber;
use App\Models\ShrinkageRecord;
use App\Models\SmsMessage;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\Tailor;
use App\Models\TailorAlteration;
use App\Models\TailorBonus;
use App\Models\TailorPayment;
use App\Models\TdsTcsRate;
use App\Models\TransportCharge;
use App\Models\Unit;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VendorRating;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    /**
     * Modules this generator knows how to populate → human label. Ordered to
     * mirror the Masters menu (project-stack.md §3): catalogue masters first,
     * then business partners, then the rest.
     */
    public const MODULES = [
        // Masters → Products
        'categories' => 'Categories',
        'brands' => 'Brands',
        'units' => 'Units',
        'products' => 'Products',
        'price_lists' => 'Price Lists',
        // Masters → Business Partners
        'customers' => 'Customers',
        'customer_categories' => 'Customer Categories',
        'suppliers' => 'Suppliers',
        'brokers' => 'Brokers',
        // Masters → Warehouses
        'warehouses' => 'Warehouses',
        // Sales
        'sales_quotations' => 'Quotations',
        'sales_orders' => 'Sales Orders',
        'pos_sales' => 'POS Sales',
        'b2b_orders' => 'B2B Orders',
        'invoices' => 'Invoices',
        'sales_returns' => 'Sales Returns',
        // CRM
        'leads' => 'Leads',
        'follow_ups' => 'Follow-ups',
        'referrals' => 'Referrals',
        'coupons' => 'Coupons',
        'marketing_campaigns' => 'Marketing Campaigns',
        'whatsapp_messages' => 'WhatsApp Messages',
        'sms_messages' => 'SMS Messages',
        'email_logs' => 'Email Log',
        // Field Sales
        'salesmen' => 'Salesmen',
        'beats' => 'Beats',
        // Purchase
        'vendor_ratings' => 'Vendor Ratings',
        // Inventory
        'stock_adjustments' => 'Stock Adjustments',
        'gate_entries' => 'Gate Entries',
        'batches' => 'Batches',
        'serial_numbers' => 'Serial Numbers',
        // Manufacturing & Textile
        'colour_printings' => 'Colour Printing',
        'fabric_rolls' => 'Fabric Rolls',
        'shrinkage_records' => 'Shrinkage Records',
        // Tailoring
        'contractors' => 'Contractors',
        'tailors' => 'Tailors',
        'rate_cards' => 'Rate Cards',
        'tailor_alterations' => 'Tailor Alterations',
        'tailor_bonuses' => 'Tailor Bonuses',
        'tailor_payments' => 'Tailor Payments',
        // Embroidery
        'embroidery_vendors' => 'Embroidery Vendors',
        'embroidery_payments' => 'Embroidery Payments',
        // Accounts & Finance
        'bank_accounts' => 'Bank Accounts',
        'vouchers' => 'Vouchers',
        'expense_categories' => 'Expense Categories',
        'assets' => 'Assets',
        'employee_claims' => 'Employee Claims',
        'vehicle_expenses' => 'Vehicle Expenses',
        'fuel_expenses' => 'Fuel Expenses',
        'electricity_bills' => 'Electricity Bills',
        'factory_maintenances' => 'Factory Maintenance',
        'asset_maintenances' => 'Asset Maintenance',
        // GST
        'gst_registrations' => 'GST Registrations',
        'hsn_codes' => 'HSN Codes',
        'tds_tcs_rates' => 'TDS/TCS Rates',
        // HR & Payroll
        'departments' => 'Departments',
        'biometric_devices' => 'Biometric Devices',
        'attendance' => 'Attendance',
        'biometric_punches' => 'Biometric Punches',
        'leave_types' => 'Leave Types',
        'leave_applications' => 'Leave Applications',
        'overtime_records' => 'Overtime',
        'salary_structures' => 'Salary Structures',
        'salaries' => 'Payroll / Salaries',
        'employee_bonuses' => 'Employee Bonuses',
        'salary_advances' => 'Salary Advances',
        'employee_loans' => 'Employee Loans',
        // Fleet & Logistics
        'vehicles' => 'Vehicles',
        'drivers' => 'Drivers',
        'delivery_routes' => 'Delivery Routes',
        'transport_charges' => 'Transport Charges',
        // E-commerce
        'channel_integrations' => 'E-commerce Integrations',
        'channel_orders' => 'Channel Orders',
        'channel_listings' => 'Channel Listings',
        'channel_sync_logs' => 'Channel Sync Logs',
        // Payments
        'payment_gateway_accounts' => 'Payment Gateways',
        'payment_transactions' => 'Payment Transactions',
        // Other operational modules
        'employees' => 'Employees',
        'expenses' => 'Expenses',
    ];

    public const COUNTS = [10, 20, 50];

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

    private array $categoryNames = ['Apparel', 'Footwear', 'Accessories', 'Home Textiles', 'Fabrics', 'Winter Wear', 'Ethnic Wear', 'Sportswear', 'Kids Wear', 'Formal Wear'];

    private array $brandNames = ['Raymond', 'Arvind', 'Vardhman', 'Bombay Dyeing', 'Fabindia', 'Peter England', 'Allen Solly', 'Van Heusen', 'Manyavar', 'Biba'];

    /** [name, short_name] pairs for units of measure. */
    private array $unitDefs = [['Pieces', 'pcs'], ['Kilogram', 'kg'], ['Metre', 'mtr'], ['Litre', 'ltr'], ['Box', 'box'], ['Dozen', 'dz'], ['Roll', 'roll'], ['Pair', 'pair'], ['Set', 'set'], ['Gram', 'gm']];

    private array $customerCategoryNames = ['Retail Walk-in', 'Wholesale', 'Distributor', 'VIP', 'Corporate', 'Online', 'Export', 'Staff', 'Reseller', 'Institutional'];

    /**
     * Generate $count records for each requested module.
     *
     * @param  array<int,string>  $modules
     * @return array<string,int>  module => rows created
     */
    public function generate(array $modules, int $count): array
    {
        $count = in_array($count, self::COUNTS, true) ? $count : self::COUNTS[0];
        $created = [];

        foreach ($modules as $module) {
            $created[$module] = match ($module) {
                'categories' => $this->make($count, fn (int $i) => $this->categoryRow($i), Category::class),
                'brands' => $this->make($count, fn (int $i) => $this->brandRow($i), Brand::class),
                'units' => $this->make($count, fn (int $i) => $this->unitRow($i), Unit::class),
                'price_lists' => $this->makePriceLists($count),
                'customers' => $this->make($count, fn (int $i) => $this->customerRow($i), Customer::class),
                'customer_categories' => $this->make($count, fn (int $i) => $this->customerCategoryRow($i), CustomerCategory::class),
                'suppliers' => $this->make($count, fn (int $i) => $this->supplierRow($i), Supplier::class),
                'products' => $this->makeProducts($count),
                'brokers' => $this->make($count, fn (int $i) => $this->brokerRow($i), Broker::class),
                'warehouses' => $this->make($count, fn (int $i) => $this->warehouseRow($i), Warehouse::class),
                'sales_quotations' => $this->makeSalesDocuments($count, SalesQuotation::class),
                'sales_orders' => $this->makeSalesDocuments($count, SalesOrder::class),
                'pos_sales' => $this->makeInvoices($count, 'pos'),
                'b2b_orders' => $this->makeSalesDocuments($count, SalesOrder::class, 'b2b'),
                'invoices' => $this->makeInvoices($count, 'standard'),
                'sales_returns' => $this->makeSalesReturns($count),
                'employees' => $this->makeEmployees($count),
                'expenses' => $this->makeExpenses($count),
                'leads' => $this->make($count, fn (int $i) => $this->leadRow($i), Lead::class),
                'follow_ups' => $this->makeFollowUps($count),
                'referrals' => $this->makeReferrals($count),
                'coupons' => $this->make($count, fn (int $i) => $this->couponRow($i), Coupon::class),
                'marketing_campaigns' => $this->make($count, fn (int $i) => $this->campaignRow($i), MarketingCampaign::class),
                'whatsapp_messages' => $this->makeWhatsapp($count),
                'sms_messages' => $this->makeSms($count),
                'email_logs' => $this->make($count, fn (int $i) => $this->emailLogRow($i), EmailLog::class),
                // Field sales
                'salesmen' => $this->make($count, fn (int $i) => $this->salesmanRow($i), Salesman::class),
                'beats' => $this->makeBeats($count),
                // Purchase
                'vendor_ratings' => $this->makeVendorRatings($count),
                // Inventory
                'stock_adjustments' => $this->makeStockAdjustments($count),
                'gate_entries' => $this->makeGateEntries($count),
                'batches' => $this->makeStockItems($count, 'batch'),
                'serial_numbers' => $this->makeStockItems($count, 'serial'),
                // Manufacturing & textile
                'colour_printings' => $this->makeColourPrintings($count),
                'fabric_rolls' => $this->makeStockItems($count, 'fabric_roll'),
                'shrinkage_records' => $this->make($count, fn (int $i) => $this->shrinkageRow($i), ShrinkageRecord::class),
                // Tailoring
                'contractors' => $this->make($count, fn (int $i) => $this->contractorRow($i), Contractor::class),
                'tailors' => $this->make($count, fn (int $i) => $this->tailorRow($i), Tailor::class),
                'rate_cards' => $this->makeRateCards($count),
                'tailor_alterations' => $this->make($count, fn (int $i) => $this->tailorAlterationRow($i), TailorAlteration::class),
                'tailor_bonuses' => $this->makeTailorLedger($count, 'bonus'),
                'tailor_payments' => $this->makeTailorLedger($count, 'payment'),
                // Embroidery
                'embroidery_vendors' => $this->make($count, fn (int $i) => $this->embroideryVendorRow($i), EmbroideryVendor::class),
                'embroidery_payments' => $this->makeEmbroideryPayments($count),
                // Accounts & finance
                'bank_accounts' => $this->make($count, fn (int $i) => $this->bankAccountRow($i), BankAccount::class),
                'vouchers' => $this->make($count, fn (int $i) => $this->voucherRow($i), Voucher::class),
                'expense_categories' => $this->make($count, fn (int $i) => $this->expenseCategoryRow($i), ExpenseCategory::class),
                'assets' => $this->make($count, fn (int $i) => $this->assetRow($i), Asset::class),
                'employee_claims' => $this->makeEmployeeDocs($count, 'claim'),
                'vehicle_expenses' => $this->makeVehicleDocs($count, 'expense'),
                'fuel_expenses' => $this->makeVehicleDocs($count, 'fuel'),
                'electricity_bills' => $this->make($count, fn (int $i) => $this->electricityBillRow($i), ElectricityBill::class),
                'factory_maintenances' => $this->make($count, fn (int $i) => $this->factoryMaintenanceRow($i), FactoryMaintenance::class),
                'asset_maintenances' => $this->makeAssetMaintenances($count),
                // GST
                'gst_registrations' => $this->makeGstRegistrations($count),
                'hsn_codes' => $this->make($count, fn (int $i) => $this->hsnRow($i), HsnCode::class),
                'tds_tcs_rates' => $this->make($count, fn (int $i) => $this->tdsTcsRow($i), TdsTcsRate::class),
                // HR & payroll
                'departments' => $this->make($count, fn (int $i) => $this->departmentRow($i), Department::class),
                'biometric_devices' => $this->make($count, fn (int $i) => $this->biometricDeviceRow($i), BiometricDevice::class),
                'leave_types' => $this->make($count, fn (int $i) => $this->leaveTypeRow($i), LeaveType::class),
                'attendance' => $this->makeEmployeeDocs($count, 'attendance'),
                'overtime_records' => $this->makeEmployeeDocs($count, 'overtime'),
                'employee_bonuses' => $this->makeEmployeeDocs($count, 'bonus'),
                'salary_advances' => $this->makeEmployeeDocs($count, 'advance'),
                'employee_loans' => $this->makeEmployeeDocs($count, 'loan'),
                'salary_structures' => $this->makeEmployeeDocs($count, 'structure'),
                'salaries' => $this->makeEmployeeDocs($count, 'salary'),
                'leave_applications' => $this->makeLeaveApplications($count),
                'biometric_punches' => $this->makeBiometricPunches($count),
                // Fleet & logistics
                'vehicles' => $this->make($count, fn (int $i) => $this->vehicleRow($i), Vehicle::class),
                'drivers' => $this->make($count, fn (int $i) => $this->driverRow($i), Driver::class),
                'delivery_routes' => $this->make($count, fn (int $i) => $this->deliveryRouteRow($i), DeliveryRoute::class),
                'transport_charges' => $this->make($count, fn (int $i) => $this->transportChargeRow($i), TransportCharge::class),
                // E-commerce
                'channel_integrations' => $this->make($count, fn (int $i) => $this->channelIntegrationRow($i), ChannelIntegration::class),
                'channel_orders' => $this->makeChannelChildren($count, 'order'),
                'channel_listings' => $this->makeChannelChildren($count, 'listing'),
                'channel_sync_logs' => $this->makeChannelChildren($count, 'sync'),
                // Payments
                'payment_gateway_accounts' => $this->make($count, fn (int $i) => $this->paymentGatewayRow($i), PaymentGatewayAccount::class),
                'payment_transactions' => $this->make($count, fn (int $i) => $this->paymentTransactionRow($i), PaymentTransaction::class),
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

    private function categoryRow(int $i): array
    {
        $base = $this->categoryNames[$i % count($this->categoryNames)];
        $name = $base . ' ' . ($i + 1);

        return [
            'name' => $name,
            // Explicit slug keeps the (tenant_id, slug) unique index safe across runs.
            'slug' => Str::slug($name) . '-' . strtolower($this->suffix($i)),
            'description' => 'Demo category for ' . $base,
            'is_active' => true,
        ];
    }

    private function brandRow(int $i): array
    {
        $base = $this->brandNames[$i % count($this->brandNames)];
        $name = $base . ' ' . ($i + 1);

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . strtolower($this->suffix($i)),
            'description' => 'Demo brand',
            'is_active' => true,
        ];
    }

    private function unitRow(int $i): array
    {
        $def = $this->unitDefs[$i % count($this->unitDefs)];

        return [
            'name' => $def[0] . ' ' . ($i + 1),
            'short_name' => substr($def[1] . ($i + 1), 0, 20),
            'conversion_factor' => 1,
            'is_active' => true,
        ];
    }

    private function customerCategoryRow(int $i): array
    {
        $base = $this->customerCategoryNames[$i % count($this->customerCategoryNames)];
        // Name carries the (tenant_id, name) unique index, so keep it distinct.
        $name = $base . ' ' . ($i + 1);

        return [
            'name' => $name,
            'code' => 'DCC-' . $this->suffix($i),
            'description' => 'Demo customer category',
            'default_price_level' => ['mrp', 'wholesale', 'retail', 'discount'][$i % 4],
            'discount_percent' => $i % 15,
            'is_active' => true,
        ];
    }

    private function warehouseRow(int $i): array
    {
        $city = $this->cities[$i % count($this->cities)];

        return [
            'code' => 'DWH-' . $this->suffix($i) . '-' . $i,
            'name' => $city . ' Warehouse ' . ($i + 1),
            'type' => ['main', 'branch', 'transit', 'damaged'][$i % 4],
            'contact_person' => $this->person($i),
            'phone' => $this->phone($i),
            'email' => 'warehouse' . $i . '.' . $this->suffix($i) . '@example.com',
            'city' => $city,
            'state' => $this->states[$i % count($this->states)],
            'country' => 'India',
            'pincode' => (string) (380000 + ($i * 7) % 99999),
            'is_active' => true,
        ];
    }

    /** Price-list rows reference existing products; returns 0 if none exist yet. */
    private function makePriceLists(int $count): int
    {
        $productIds = Product::pluck('id')->all();
        if (empty($productIds)) {
            return 0;
        }
        $levels = ['mrp', 'wholesale', 'retail', 'discount'];

        return $this->make($count, function (int $i) use ($productIds, $levels) {
            return [
                'product_id' => $productIds[$i % count($productIds)],
                'level' => $levels[$i % count($levels)],
                'price' => ($i % 100 + 5) * 25,
                'min_quantity' => [1, 5, 10, 25][$i % 4],
            ];
        }, PriceList::class);
    }

    private function couponRow(int $i): array
    {
        $percent = $i % 2 === 0;

        return [
            'code' => 'DCPN' . $this->suffix($i) . $i,
            'description' => $percent ? 'Demo % discount coupon' : 'Demo flat discount coupon',
            'discount_type' => $percent ? 'percent' : 'flat',
            'discount_value' => $percent ? [5, 10, 15, 20, 25][$i % 5] : [100, 250, 500][$i % 3],
            'max_discount' => $percent ? 1000 : null,
            'min_order_value' => ($i % 5) * 500,
            'usage_limit' => [null, 50, 100, 500][$i % 4],
            'usage_limit_per_customer' => [1, 2, 3][$i % 3],
            'used_count' => 0,
            'valid_from' => now()->subDays($i % 30)->toDateString(),
            'valid_to' => now()->addDays(30 + $i % 60)->toDateString(),
            'is_active' => true,
        ];
    }

    private function campaignRow(int $i): array
    {
        $statuses = ['draft', 'sending', 'sent', 'failed'];
        $status = $statuses[$i % count($statuses)];

        return [
            'name' => 'Demo Campaign ' . ($i + 1),
            'subject' => ['Festive Offer', 'New Arrivals', 'Season Sale', 'Loyalty Rewards', 'Clearance'][$i % 5] . ' #' . ($i + 1),
            'body' => 'This is a demo marketing campaign body for exploring the CRM.',
            'audience' => 'all',
            'status' => $status,
            'recipient_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'sent_at' => $status === 'sent' ? now()->subDays($i % 20) : null,
            'created_by' => $this->createdBy,
        ];
    }

    /** Follow-ups attach (morph) to existing customers; 0 if none exist yet. */
    private function makeFollowUps(int $count): int
    {
        $customerIds = Customer::pluck('id')->all();
        if (empty($customerIds)) {
            return 0;
        }
        $types = ['call', 'visit', 'email', 'whatsapp', 'other'];
        $statuses = ['pending', 'done', 'cancelled'];
        $userId = $this->createdBy;

        return $this->make($count, function (int $i) use ($customerIds, $types, $statuses, $userId) {
            $status = $statuses[$i % count($statuses)];

            return [
                'followupable_type' => Customer::class,
                'followupable_id' => $customerIds[$i % count($customerIds)],
                'type' => $types[$i % count($types)],
                'due_at' => now()->addDays(($i % 30) - 10),
                'title' => 'Follow up #' . ($i + 1),
                'notes' => 'Demo follow-up note',
                'status' => $status,
                'completed_at' => $status === 'done' ? now()->subDays($i % 10) : null,
                'outcome' => $status === 'done' ? 'Spoke with the customer.' : null,
                'assigned_to' => $userId,
                'created_by' => $userId,
            ];
        }, FollowUp::class);
    }

    /** Referrals reference an existing referrer customer; 0 if none exist yet. */
    private function makeReferrals(int $count): int
    {
        $customerIds = Customer::pluck('id')->all();
        if (empty($customerIds)) {
            return 0;
        }
        $statuses = ['pending', 'joined', 'rewarded', 'cancelled'];
        $userId = $this->createdBy;

        return $this->make($count, function (int $i) use ($customerIds, $statuses, $userId) {
            $status = $statuses[$i % count($statuses)];

            return [
                'referrer_customer_id' => $customerIds[$i % count($customerIds)],
                'referred_name' => $this->person($i),
                'referred_phone' => $this->phone($i),
                'status' => $status,
                'reward_points' => in_array($status, ['rewarded'], true) ? ($i % 10 + 1) * 50 : 0,
                'notes' => 'Demo referral',
                'joined_at' => in_array($status, ['joined', 'rewarded'], true) ? now()->subDays($i % 30) : null,
                'rewarded_at' => $status === 'rewarded' ? now()->subDays($i % 15) : null,
                'created_by' => $userId,
            ];
        }, Referral::class);
    }

    /**
     * Build 1–3 demo line rows plus running subtotal/tax/total for a sales
     * document. Shared by quotations, orders, invoiced sales and returns.
     *
     * @return array{lines:array<int,array>,subtotal:float,tax:float,total:float}
     */
    private function buildSaleLines(int $i, $products, int $productCount): array
    {
        $lineCount = ($i % 3) + 1;
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $lines = [];

        for ($l = 0; $l < $lineCount; $l++) {
            $p = $products[($i + $l) % $productCount];
            $qty = ($i % 5) + 1;
            $price = (float) ($p->selling_price ?: 100);
            $taxPct = (float) $p->tax_percent;
            $lineBase = $qty * $price;
            $taxAmt = round($lineBase * $taxPct / 100, 2);
            $subtotal += $lineBase;
            $taxTotal += $taxAmt;
            $lines[] = [
                'product_id' => $p->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_percent' => $taxPct,
                'tax_amount' => $taxAmt,
                'line_total' => round($lineBase + $taxAmt, 2),
            ];
        }

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'tax' => round($taxTotal, 2),
            'total' => round($subtotal + $taxTotal, 2),
        ];
    }

    /**
     * Generate sales documents (quotations or orders) with 1–3 line items each.
     * Both share the same header/line shape; only the number field + item
     * relation name differ. Needs customers, warehouses and products; returns 0
     * if any of those are missing. A non-null $channel (e.g. 'b2b') is stamped on
     * orders and leaves them unapproved (pending) for the approval flow.
     *
     * @param  class-string  $model  SalesOrder or SalesQuotation
     */
    private function makeSalesDocuments(int $count, string $model, ?string $channel = null): int
    {
        $customerIds = Customer::pluck('id')->all();
        $warehouseIds = Warehouse::pluck('id')->all();
        $products = Product::select('id', 'selling_price', 'tax_percent')->get();
        if (empty($customerIds) || empty($warehouseIds) || $products->isEmpty()) {
            return 0;
        }

        $isOrder = $model === SalesOrder::class;
        $isB2b = $channel === 'b2b';
        $numberField = $isOrder ? 'so_number' : 'quote_number';
        $dateField = $isOrder ? 'order_date' : 'quote_date';
        $prefix = $isB2b ? 'DB2B-' : ($isOrder ? 'DSO-' : 'DSQ-');
        // B2B orders sit in the pending-approval state (draft + not yet approved).
        $statuses = $isB2b
            ? ['draft']
            : ($isOrder
                ? ['draft', 'confirmed', 'delivered', 'cancelled']
                : ['draft', 'sent', 'accepted', 'rejected', 'expired']);
        $userId = $this->createdBy;
        $productCount = $products->count();

        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use (
                $model, $isOrder, $channel, $numberField, $dateField, $prefix, $statuses,
                $userId, $customerIds, $warehouseIds, $products, $productCount, $start, $batch, &$made
            ) {
                for ($k = 0; $k < $batch; $k++) {
                    $i = $start + $k;
                    $built = $this->buildSaleLines($i, $products, $productCount);

                    $header = [
                        $numberField => $prefix . $this->suffix($i) . '-' . $i,
                        'customer_id' => $customerIds[$i % count($customerIds)],
                        'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                        'status' => $statuses[$i % count($statuses)],
                        $dateField => now()->subDays($i % 180)->toDateString(),
                        'subtotal' => $built['subtotal'],
                        'tax_amount' => $built['tax'],
                        'total' => $built['total'],
                        'created_by' => $userId,
                    ];
                    if ($isOrder && $channel) {
                        $header['channel'] = $channel;
                    }
                    if (! $isOrder) {
                        $header['valid_until'] = now()->addDays(15)->toDateString();
                    }

                    $doc = $model::create($header);
                    $doc->items()->createMany($built['lines']);
                    $made++;
                }
            });
        }

        return $made;
    }

    /**
     * Generate billed sales: a delivered sales order + its invoice + a payment.
     * $channel 'pos' produces fully-paid cash POS sales; 'standard' varies the
     * paid/partial/unpaid status. Invoice dates are spread over the last 20 days
     * so the dashboard's sales trend has data. Needs customers, warehouses and
     * products; returns 0 if any are missing.
     */
    private function makeInvoices(int $count, string $channel = 'standard'): int
    {
        $customerIds = Customer::pluck('id')->all();
        $warehouseIds = Warehouse::pluck('id')->all();
        $products = Product::select('id', 'selling_price', 'tax_percent')->get();
        if (empty($customerIds) || empty($warehouseIds) || $products->isEmpty()) {
            return 0;
        }

        $isPos = $channel === 'pos';
        $userId = $this->createdBy;
        $productCount = $products->count();

        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use (
                $isPos, $channel, $userId, $customerIds, $warehouseIds, $products, $productCount, $start, $batch, &$made
            ) {
                for ($k = 0; $k < $batch; $k++) {
                    $i = $start + $k;
                    $customerId = $customerIds[$i % count($customerIds)];
                    $built = $this->buildSaleLines($i, $products, $productCount);
                    $total = $built['total'];
                    $date = now()->subDays($i % 20);

                    $order = SalesOrder::create([
                        'so_number' => ($isPos ? 'DPOS-SO-' : 'DINV-SO-') . $this->suffix($i) . '-' . $i,
                        'customer_id' => $customerId,
                        'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                        'status' => 'delivered',
                        'channel' => $channel,
                        'order_date' => $date->toDateString(),
                        'subtotal' => $built['subtotal'],
                        'tax_amount' => $built['tax'],
                        'total' => $total,
                        'created_by' => $userId,
                        'delivered_at' => $date,
                    ]);
                    $order->items()->createMany($built['lines']);

                    // POS = paid in full (cash). Standard = rotate paid/partial/unpaid.
                    if ($isPos) {
                        $paid = $total;
                        $status = 'paid';
                    } else {
                        $r = $i % 3;
                        $paid = $r === 0 ? $total : ($r === 1 ? round($total * 0.5, 2) : 0.0);
                        $status = $r === 0 ? 'paid' : ($r === 1 ? 'partial' : 'unpaid');
                    }

                    $invoice = Invoice::create([
                        'invoice_number' => ($isPos ? 'DPOS-' : 'DINV-') . $this->suffix($i) . '-' . $i,
                        'sales_order_id' => $order->id,
                        'customer_id' => $customerId,
                        'invoice_date' => $date->toDateString(),
                        'total' => $total,
                        'paid_amount' => $paid,
                        'status' => $status,
                    ]);

                    if ($paid > 0) {
                        Payment::create([
                            'invoice_id' => $invoice->id,
                            'amount' => $paid,
                            'mode' => $isPos ? 'cash' : ['cash', 'card', 'upi'][$i % 3],
                            'payment_date' => $date->toDateString(),
                            'created_by' => $userId,
                        ]);
                    }
                    $made++;
                }
            });
        }

        return $made;
    }

    /** Credit notes against existing invoices; 0 if there are no invoices yet. */
    private function makeSalesReturns(int $count): int
    {
        $invoices = Invoice::select('id', 'customer_id')->get();
        $warehouseIds = Warehouse::pluck('id')->all();
        $products = Product::select('id', 'selling_price', 'tax_percent')->get();
        if ($invoices->isEmpty() || empty($warehouseIds) || $products->isEmpty()) {
            return 0;
        }
        $statuses = ['draft', 'approved', 'cancelled'];
        $userId = $this->createdBy;
        $productCount = $products->count();
        $invoiceCount = $invoices->count();

        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use (
                $invoices, $invoiceCount, $warehouseIds, $products, $productCount, $statuses, $userId, $start, $batch, &$made
            ) {
                for ($k = 0; $k < $batch; $k++) {
                    $i = $start + $k;
                    $inv = $invoices[$i % $invoiceCount];
                    $built = $this->buildSaleLines($i, $products, $productCount);
                    $status = $statuses[$i % count($statuses)];

                    $return = SalesReturn::create([
                        'credit_note_number' => 'DCN-' . $this->suffix($i) . '-' . $i,
                        'invoice_id' => $inv->id,
                        'customer_id' => $inv->customer_id,
                        'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                        'status' => $status,
                        'return_date' => now()->subDays($i % 30)->toDateString(),
                        'subtotal' => $built['subtotal'],
                        'tax_amount' => $built['tax'],
                        'total' => $built['total'],
                        'notes' => 'Demo credit note',
                        'created_by' => $userId,
                        'approved_by' => $status === 'approved' ? $userId : null,
                        'approved_at' => $status === 'approved' ? now() : null,
                    ]);
                    $lines = array_map(fn ($l) => $l + ['reason' => 'Demo return reason'], $built['lines']);
                    $return->items()->createMany($lines);
                    $made++;
                }
            });
        }

        return $made;
    }

    private function makeWhatsapp(int $count): int
    {
        $customerIds = Customer::pluck('id')->all();
        $templates = ['invoice', 'payment-reminder', 'birthday', 'promo', 'manual'];
        $statuses = ['queued', 'sent', 'delivered', 'failed'];
        $userId = $this->createdBy;

        return $this->make($count, function (int $i) use ($customerIds, $templates, $statuses, $userId) {
            $phone = $this->phone($i);

            return [
                'customer_id' => $customerIds ? $customerIds[$i % count($customerIds)] : null,
                'phone' => $phone,
                'template' => $templates[$i % count($templates)],
                'body' => 'Demo WhatsApp message #' . ($i + 1),
                'channel' => $i % 2 === 0 ? 'click_to_chat' : 'api',
                'status' => $statuses[$i % count($statuses)],
                'link' => 'https://wa.me/91' . $phone,
                'created_by' => $userId,
            ];
        }, WhatsappMessage::class);
    }

    private function makeSms(int $count): int
    {
        $customerIds = Customer::pluck('id')->all();
        $templates = ['otp', 'invoice', 'dispatch', 'payment-reminder', 'festival', 'manual'];
        $statuses = ['queued', 'sent', 'delivered', 'failed'];
        $userId = $this->createdBy;

        return $this->make($count, function (int $i) use ($customerIds, $templates, $statuses, $userId) {
            return [
                'customer_id' => $customerIds ? $customerIds[$i % count($customerIds)] : null,
                'phone' => $this->phone($i),
                'template' => $templates[$i % count($templates)],
                'body' => 'Demo SMS message #' . ($i + 1),
                'provider' => 'log',
                'status' => $statuses[$i % count($statuses)],
                'created_by' => $userId,
            ];
        }, SmsMessage::class);
    }

    private function emailLogRow(int $i): array
    {
        $statuses = ['queued', 'sent', 'failed'];
        $status = $statuses[$i % count($statuses)];

        return [
            'mailable' => ['invoice', 'quotation', 'campaign', 'test'][$i % 4],
            'to_email' => 'demo' . $i . '.' . $this->suffix($i) . '@example.com',
            'to_name' => $this->person($i),
            'subject' => 'Demo email #' . ($i + 1),
            'status' => $status,
            'error' => $status === 'failed' ? 'Demo delivery failure' : null,
            'sent_at' => $status === 'sent' ? now()->subDays($i % 20) : null,
            'created_by' => $this->createdBy,
        ];
    }

    // ---- Standalone master row builders (used via make()) ----

    private function salesmanRow(int $i): array
    {
        return [
            'code' => 'DSMN-' . $this->suffix($i) . '-' . $i,
            'name' => $this->person($i),
            'mobile' => $this->phone($i),
            'email' => 'salesman' . $i . '.' . $this->suffix($i) . '@example.com',
            'territory' => $this->cities[$i % count($this->cities)],
            'default_incentive_percent' => [1, 2, 3][$i % 3],
            'is_active' => true,
        ];
    }

    private function contractorRow(int $i): array
    {
        return [
            'code' => 'DCON-' . $this->suffix($i) . '-' . $i,
            'name' => $this->company($i),
            'mobile' => $this->phone($i),
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }

    private function tailorRow(int $i): array
    {
        return [
            'code' => 'DTLR-' . $this->suffix($i) . '-' . $i,
            'name' => $this->person($i),
            'mobile' => $this->phone($i),
            'skill' => ['stitching', 'cutting', 'finishing'][$i % 3],
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }

    private function embroideryVendorRow(int $i): array
    {
        return [
            'code' => 'DEMBV-' . $this->suffix($i) . '-' . $i,
            'name' => $this->company($i),
            'mobile' => $this->phone($i),
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }

    private function bankAccountRow(int $i): array
    {
        return [
            'name' => 'Demo Bank A/C ' . ($i + 1),
            'type' => 'bank',
            'bank_name' => ['HDFC Bank', 'ICICI Bank', 'State Bank of India', 'Axis Bank'][$i % 4],
            'account_number' => (string) (50100000000 + $i),
            'ifsc' => 'HDFC0' . str_pad((string) ($i % 999999), 6, '0', STR_PAD_LEFT),
            'opening_balance' => ($i % 20) * 1000,
            'is_active' => true,
        ];
    }

    private function voucherRow(int $i): array
    {
        return [
            'voucher_number' => 'DVCH-' . $this->suffix($i) . '-' . $i,
            'voucher_type' => ['receipt', 'payment', 'contra', 'journal'][$i % 4],
            'voucher_date' => now()->subDays($i % 120)->toDateString(),
            'amount' => ($i % 50 + 1) * 500,
            'narration' => 'Demo voucher entry #' . ($i + 1),
            'status' => 'posted',
            'created_by' => $this->createdBy,
        ];
    }

    private function expenseCategoryRow(int $i): array
    {
        return [
            'name' => 'Demo Expense Category ' . ($i + 1),
            'is_active' => true,
        ];
    }

    private function assetRow(int $i): array
    {
        return [
            'asset_code' => 'DAST-' . $this->suffix($i) . '-' . $i,
            'name' => ['Sewing Machine', 'Cutting Table', 'Generator', 'Computer', 'Delivery Van'][$i % 5] . ' ' . ($i + 1),
            'category' => 'other',
            'purchase_date' => now()->subDays($i % 1000 + 30)->toDateString(),
            'purchase_value' => ($i % 50 + 5) * 5000,
            'is_active' => true,
        ];
    }

    private function electricityBillRow(int $i): array
    {
        $units = $i % 500 + 50;

        return [
            'bill_number' => 'DELEC-' . $this->suffix($i) . '-' . $i,
            'meter_number' => 'MTR' . str_pad((string) ($i % 9999), 4, '0', STR_PAD_LEFT),
            'provider' => ['MSEB', 'Torrent Power', 'Adani Electricity', 'Tata Power'][$i % 4],
            'bill_date' => now()->subDays($i % 120)->toDateString(),
            'units_consumed' => $units,
            'amount' => $units * 8,
            'payment_method' => 'bank',
            'status' => 'draft',
            'created_by' => $this->createdBy,
        ];
    }

    private function factoryMaintenanceRow(int $i): array
    {
        return [
            'maintenance_number' => 'DFMNT-' . $this->suffix($i) . '-' . $i,
            'area' => ['Cutting Floor', 'Stitching Floor', 'Packing', 'Dyeing Unit'][$i % 4],
            'maintenance_type' => 'breakdown',
            'maintenance_date' => now()->subDays($i % 120)->toDateString(),
            'amount' => ($i % 100 + 1) * 300,
            'payment_method' => 'cash',
            'status' => 'draft',
            'created_by' => $this->createdBy,
        ];
    }

    private function hsnRow(int $i): array
    {
        return [
            'code' => (string) (61000000 + $i),
            'type' => 'goods',
            'description' => 'Demo HSN description #' . ($i + 1),
            'gst_rate' => [0, 5, 12, 18][$i % 4],
            'is_active' => true,
        ];
    }

    private function tdsTcsRow(int $i): array
    {
        return [
            'section' => 'D' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'nature' => ['tds', 'tcs'][$i % 2],
            'rate' => [1, 2, 5, 10][$i % 4],
            'threshold' => [0, 50000, 100000][$i % 3],
            'description' => 'Demo TDS/TCS section',
            'is_active' => true,
        ];
    }

    private function departmentRow(int $i): array
    {
        return [
            'code' => 'DDEPT-' . $this->suffix($i) . '-' . $i,
            'name' => ['Production', 'Sales', 'Accounts', 'Stores', 'HR', 'Dispatch'][$i % 6] . ' ' . ($i + 1),
            'description' => 'Demo department',
            'is_active' => true,
        ];
    }

    private function biometricDeviceRow(int $i): array
    {
        return [
            'code' => 'DBIO-' . $this->suffix($i) . '-' . $i,
            'name' => 'Demo Device ' . ($i + 1),
            'location' => $this->cities[$i % count($this->cities)] . ' Gate',
            'serial_no' => 'SN' . $this->suffix($i),
            'ip_address' => '192.168.1.' . ($i % 250 + 2),
            'is_active' => true,
        ];
    }

    private function leaveTypeRow(int $i): array
    {
        return [
            'code' => 'DLT-' . $this->suffix($i) . '-' . $i,
            'name' => ['Casual Leave', 'Sick Leave', 'Earned Leave', 'Maternity Leave', 'Comp Off'][$i % 5],
            'annual_quota' => [6, 12, 15][$i % 3],
            'is_paid' => true,
            'carry_forward' => $i % 2 === 0,
            'is_active' => true,
        ];
    }

    private function vehicleRow(int $i): array
    {
        return [
            'registration_no' => 'DMH' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'name' => ['Tempo', 'Truck', 'Van', 'Pickup'][$i % 4] . ' ' . ($i + 1),
            'type' => 'other',
            'make' => ['Tata', 'Mahindra', 'Ashok Leyland', 'Eicher'][$i % 4],
            'model' => 'Model ' . ($i % 20 + 1),
            'fuel_type' => 'diesel',
            'is_active' => true,
        ];
    }

    private function driverRow(int $i): array
    {
        return [
            'code' => 'DDRV-' . $this->suffix($i) . '-' . $i,
            'name' => $this->person($i),
            'phone' => $this->phone($i),
            'license_no' => 'DL' . str_pad((string) ($i % 99999), 5, '0', STR_PAD_LEFT),
            'license_type' => 'LMV',
            'is_active' => true,
        ];
    }

    private function deliveryRouteRow(int $i): array
    {
        $city = $this->cities[$i % count($this->cities)];

        return [
            'code' => 'DRT-' . $this->suffix($i) . '-' . $i,
            'name' => $city . ' Route ' . ($i + 1),
            'area' => $city,
            'distance_km' => $i % 200 + 5,
            'default_charge' => ($i % 20 + 1) * 100,
            'is_active' => true,
        ];
    }

    private function transportChargeRow(int $i): array
    {
        return [
            'charge_number' => 'DTRC-' . $this->suffix($i) . '-' . $i,
            'charge_date' => now()->subDays($i % 120)->toDateString(),
            'transporter_name' => $this->company($i),
            'lr_number' => 'LR' . str_pad((string) ($i % 99999), 5, '0', STR_PAD_LEFT),
            'charge_type' => 'freight',
            'payment_mode' => 'paid',
            'payer' => 'company',
            'amount' => ($i % 50 + 1) * 200,
            'created_by' => $this->createdBy,
        ];
    }

    private function channelIntegrationRow(int $i): array
    {
        return [
            'platform' => ['shopify', 'woocommerce', 'amazon', 'flipkart', 'magento', 'custom'][$i % 6],
            'name' => 'Demo Store ' . ($i + 1),
            'status' => ['disconnected', 'connected', 'error'][$i % 3],
            'auto_sync_orders' => false,
            'auto_push_inventory' => false,
        ];
    }

    private function paymentGatewayRow(int $i): array
    {
        return [
            'gateway' => ['razorpay', 'payu', 'cashfree', 'stripe', 'instamojo'][$i % 5],
            'label' => 'Demo Gateway ' . ($i + 1),
            'mode' => ['test', 'live'][$i % 2],
            'is_active' => true,
            'is_default' => false,
        ];
    }

    private function paymentTransactionRow(int $i): array
    {
        // public_token is auto-generated by the model's creating hook.
        return [
            'gateway' => ['razorpay', 'payu', 'cashfree'][$i % 3],
            'amount' => ($i % 100 + 1) * 100,
            'currency' => 'INR',
            'status' => ['created', 'pending', 'paid', 'failed'][$i % 4],
            'created_by' => $this->createdBy,
        ];
    }

    private function shrinkageRow(int $i): array
    {
        return [
            'record_number' => 'DSHR-' . $this->suffix($i) . '-' . $i,
            'process' => ['wash', 'steam', 'heat-set'][$i % 3],
            'length_before' => 100,
            'length_after' => 100 - ($i % 10),
            'width_before' => 50,
            'width_after' => 50 - ($i % 5),
            'length_shrinkage_pct' => $i % 10,
            'width_shrinkage_pct' => $i % 5,
            'test_date' => now()->subDays($i % 90)->toDateString(),
            'created_by' => $this->createdBy,
        ];
    }

    private function tailorAlterationRow(int $i): array
    {
        return [
            'alteration_number' => 'DALT-' . $this->suffix($i) . '-' . $i,
            'reason' => ['size mismatch', 'stitching defect', 'customer request'][$i % 3],
            'qty' => $i % 5 + 1,
            'charge' => ($i % 10) * 20,
            'status' => 'pending',
            'reported_date' => now()->subDays($i % 60)->toDateString(),
            'created_by' => $this->createdBy,
        ];
    }

    // ---- Dependency-backed builders ----

    private function makeBeats(int $count): int
    {
        $salesmanIds = Salesman::pluck('id')->all();
        if (empty($salesmanIds)) {
            return 0;
        }
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        return $this->make($count, fn (int $i) => [
            'salesman_id' => $salesmanIds[$i % count($salesmanIds)],
            'name' => 'Beat ' . ($i + 1),
            'day_of_week' => $days[$i % count($days)],
            'area' => $this->cities[$i % count($this->cities)],
            'is_active' => true,
        ], Beat::class);
    }

    private function makeVendorRatings(int $count): int
    {
        $supplierIds = Supplier::pluck('id')->all();
        if (empty($supplierIds)) {
            return 0;
        }

        return $this->make($count, function (int $i) use ($supplierIds) {
            $q = $i % 5 + 1;
            $d = ($i + 1) % 5 + 1;
            $p = ($i + 2) % 5 + 1;
            $s = ($i + 3) % 5 + 1;

            return [
                'supplier_id' => $supplierIds[$i % count($supplierIds)],
                'quality_score' => $q,
                'delivery_score' => $d,
                'pricing_score' => $p,
                'service_score' => $s,
                'overall_score' => round(($q + $d + $p + $s) / 4, 2),
                'rated_on' => now()->subDays($i % 120)->toDateString(),
                'remarks' => 'Demo vendor rating',
                'rated_by' => $this->createdBy,
            ];
        }, VendorRating::class);
    }

    /**
     * Stock adjustments — a header (warehouse + reason) plus 1-3 signed item
     * lines keyed to a product. Needs warehouses and products; returns 0 if
     * either is missing.
     */
    private function makeStockAdjustments(int $count): int
    {
        $warehouseIds = Warehouse::pluck('id')->all();
        $productIds = Product::pluck('id')->all();
        if (empty($warehouseIds) || empty($productIds)) {
            return 0;
        }

        $reasons = array_keys(StockAdjustment::LEDGER_TYPES);
        $statuses = ['draft', 'approved', 'cancelled'];
        $userId = $this->createdBy;
        $productCount = count($productIds);

        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use (
                $warehouseIds, $productIds, $productCount, $reasons, $statuses, $userId, $start, $batch, &$made
            ) {
                for ($k = 0; $k < $batch; $k++) {
                    $i = $start + $k;
                    $reason = $reasons[$i % count($reasons)];
                    // Losses (damage/theft/consumption/samples) remove stock;
                    // opening/correction/mismatch add it back.
                    $sign = in_array($reason, ['damage', 'theft', 'sample_issue', 'consumption'], true) ? -1 : 1;

                    $adjustment = StockAdjustment::create([
                        'adjustment_number' => 'DADJ-' . $this->suffix($i) . '-' . $i,
                        'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                        'reason' => $reason,
                        'status' => $statuses[$i % count($statuses)],
                        'adjustment_date' => now()->subDays($i % 120)->toDateString(),
                        'notes' => 'Demo stock adjustment',
                        'created_by' => $userId,
                    ]);

                    $lineCount = $i % 3 + 1;
                    $lines = [];
                    for ($l = 0; $l < $lineCount; $l++) {
                        $lines[] = [
                            'product_id' => $productIds[($i + $l) % $productCount],
                            'quantity' => $sign * (($i + $l) % 20 + 1),
                            'unit_cost' => (($i + $l) % 50 + 5) * 10,
                        ];
                    }
                    $adjustment->items()->createMany($lines);
                    $made++;
                }
            });
        }

        return $made;
    }

    private function makeGateEntries(int $count): int
    {
        $warehouseIds = Warehouse::pluck('id')->all();
        if (empty($warehouseIds)) {
            return 0;
        }

        return $this->make($count, fn (int $i) => [
            'entry_number' => 'DGATE-' . $this->suffix($i) . '-' . $i,
            'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
            'direction' => ['inward', 'outward'][$i % 2],
            'vehicle_number' => 'MH' . str_pad((string) ($i % 9999), 4, '0', STR_PAD_LEFT),
            'purpose' => 'Demo gate entry',
            'status' => 'open',
            'gate_in_at' => now()->subDays($i % 30),
        ], GateEntry::class);
    }

    /** Batches / serials / fabric rolls — all keyed to a product + warehouse. */
    private function makeStockItems(int $count, string $kind): int
    {
        $productIds = Product::pluck('id')->all();
        $warehouseIds = Warehouse::pluck('id')->all();
        if (empty($productIds) || empty($warehouseIds)) {
            return 0;
        }

        $model = match ($kind) {
            'batch' => Batch::class,
            'serial' => SerialNumber::class,
            'fabric_roll' => FabricRoll::class,
        };

        return $this->make($count, function (int $i) use ($kind, $productIds, $warehouseIds) {
            $productId = $productIds[$i % count($productIds)];
            $warehouseId = $warehouseIds[$i % count($warehouseIds)];

            return match ($kind) {
                'batch' => [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'batch_number' => 'DBAT-' . $this->suffix($i) . '-' . $i,
                    'expiry_date' => now()->addDays(180 + $i % 365)->toDateString(),
                    'quantity' => $i % 100 + 1,
                    'unit_cost' => ($i % 50 + 5) * 10,
                ],
                'serial' => [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'serial' => 'DSN-' . $this->suffix($i) . '-' . $i,
                    'status' => 'in_stock',
                ],
                'fabric_roll' => [
                    'roll_number' => 'DROLL-' . $this->suffix($i) . '-' . $i,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'shade_no' => 'SH-' . ($i % 50 + 1),
                    'gsm' => 120 + ($i % 80),
                    'initial_length' => 100,
                    'current_length' => 100,
                    'unit_cost' => ($i % 50 + 5) * 10,
                    'grade' => ['A', 'B', 'C'][$i % 3],
                    'status' => 'available',
                    'received_date' => now()->subDays($i % 120)->toDateString(),
                ],
            };
        }, $model);
    }

    private function makeColourPrintings(int $count): int
    {
        $supplierIds = Supplier::pluck('id')->all();
        if (empty($supplierIds)) {
            return 0;
        }

        return $this->make($count, fn (int $i) => [
            'printer_vendor_id' => $supplierIds[$i % count($supplierIds)],
            'print_type' => ['screen', 'digital', 'sublimation'][$i % 3],
            'screen_count' => $i % 5,
            'colour_count' => $i % 6 + 1,
            'cost' => ($i % 50 + 1) * 100,
            'status' => 'pending',
        ], ColourPrinting::class);
    }

    /** One rate card per product (unique), each with 2–3 piece-rate operations. */
    private function makeRateCards(int $count): int
    {
        $productIds = Product::pluck('id')->all();
        if (empty($productIds)) {
            return 0;
        }
        // Unique (tenant, product_id) → at most one card per product.
        $limit = min($count, count($productIds));
        $ops = ['Cutting', 'Stitching', 'Finishing'];

        $made = 0;
        for ($start = 0; $start < $limit; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $limit - $start);
            DB::transaction(function () use ($productIds, $ops, $start, $batch, &$made) {
                for ($k = 0; $k < $batch; $k++) {
                    $i = $start + $k;
                    $card = RateCard::create([
                        'product_id' => $productIds[$i],
                        'standard_rate' => ($i % 50 + 5) * 10,
                        'is_active' => true,
                    ]);
                    foreach ($ops as $seq => $op) {
                        $card->pieceRates()->create([
                            'operation' => $op,
                            'rate' => ($seq + 1) * 15,
                            'sequence' => $seq + 1,
                        ]);
                    }
                    $made++;
                }
            });
        }

        return $made;
    }

    /** Tailor bonuses / payments — keyed to an existing tailor. */
    private function makeTailorLedger(int $count, string $kind): int
    {
        $tailorIds = Tailor::pluck('id')->all();
        if (empty($tailorIds)) {
            return 0;
        }

        if ($kind === 'bonus') {
            return $this->make($count, fn (int $i) => [
                'tailor_id' => $tailorIds[$i % count($tailorIds)],
                'bonus_number' => 'DTB-' . $this->suffix($i) . '-' . $i,
                'basis' => 'manual',
                'amount' => ($i % 20 + 1) * 100,
                'status' => 'draft',
                'created_by' => $this->createdBy,
            ], TailorBonus::class);
        }

        return $this->make($count, fn (int $i) => [
            'tailor_id' => $tailorIds[$i % count($tailorIds)],
            'payment_number' => 'DTP-' . $this->suffix($i) . '-' . $i,
            'amount' => ($i % 20 + 1) * 200,
            'mode' => 'cash',
            'payment_date' => now()->subDays($i % 90)->toDateString(),
            'created_by' => $this->createdBy,
        ], TailorPayment::class);
    }

    private function makeEmbroideryPayments(int $count): int
    {
        $vendorIds = EmbroideryVendor::pluck('id')->all();
        if (empty($vendorIds)) {
            return 0;
        }

        return $this->make($count, fn (int $i) => [
            'embroidery_vendor_id' => $vendorIds[$i % count($vendorIds)],
            'payment_number' => 'DEP-' . $this->suffix($i) . '-' . $i,
            'amount' => ($i % 20 + 1) * 200,
            'mode' => 'cash',
            'payment_date' => now()->subDays($i % 90)->toDateString(),
            'created_by' => $this->createdBy,
        ], EmbroideryPayment::class);
    }

    /** Employee-scoped HR/payroll records. Needs employees; 0 if none exist. */
    private function makeEmployeeDocs(int $count, string $kind): int
    {
        $employeeIds = Employee::pluck('id')->all();
        if (empty($employeeIds)) {
            return 0;
        }
        $userId = $this->createdBy;
        $n = count($employeeIds);

        $model = match ($kind) {
            'claim' => EmployeeClaim::class,
            'attendance' => Attendance::class,
            'overtime' => OvertimeRecord::class,
            'bonus' => EmployeeBonus::class,
            'advance' => SalaryAdvance::class,
            'loan' => EmployeeLoan::class,
            'structure' => SalaryStructure::class,
            'salary' => Salary::class,
        };

        return $this->make($count, function (int $i) use ($kind, $employeeIds, $n, $userId) {
            $emp = $employeeIds[$i % $n];

            return match ($kind) {
                'claim' => [
                    'claim_number' => 'DCLM-' . $this->suffix($i) . '-' . $i,
                    'employee_id' => $emp,
                    'claim_date' => now()->subDays($i % 120)->toDateString(),
                    'title' => 'Demo expense claim #' . ($i + 1),
                    'total_amount' => ($i % 50 + 1) * 100,
                    'payment_method' => 'cash',
                    'status' => 'draft',
                    'created_by' => $userId,
                ],
                // (employee, date) is unique — subDays($i) keeps each row's date distinct.
                'attendance' => [
                    'employee_id' => $emp,
                    'date' => now()->subDays($i)->toDateString(),
                    'status' => ['present', 'absent'][$i % 2],
                    'check_in' => $i % 2 === 0 ? '09:30:00' : null,
                    'check_out' => $i % 2 === 0 ? '18:30:00' : null,
                ],
                'overtime' => [
                    'employee_id' => $emp,
                    'date' => now()->subDays($i)->toDateString(),
                    'hours' => $i % 4 + 1,
                    'rate' => 100,
                    'amount' => ($i % 4 + 1) * 100,
                    'status' => 'pending',
                ],
                'bonus' => [
                    'employee_id' => $emp,
                    'month' => now()->subMonths($i)->format('Y-m-01'),
                    'type' => 'performance',
                    'amount' => ($i % 20 + 1) * 500,
                    'status' => 'pending',
                ],
                'advance' => [
                    'employee_id' => $emp,
                    'date' => now()->subDays($i % 120)->toDateString(),
                    'amount' => ($i % 10 + 1) * 1000,
                    'monthly_recovery' => 500,
                    'recovered' => 0,
                    'status' => 'pending',
                ],
                'loan' => [
                    'employee_id' => $emp,
                    'loan_number' => 'DLOAN-' . $this->suffix($i) . '-' . $i,
                    'principal' => ($i % 20 + 5) * 5000,
                    'interest_rate' => 10,
                    'tenure_months' => 12,
                    'emi' => round((($i % 20 + 5) * 5000) / 12, 2),
                    'total_payable' => ($i % 20 + 5) * 5000,
                    'disbursed_on' => now()->subDays($i % 200)->toDateString(),
                    'status' => 'pending',
                ],
                'structure' => [
                    'employee_id' => $emp,
                    'effective_from' => now()->subMonths($i % 24)->format('Y-m-01'),
                    'basic' => 15000,
                    'hra' => 6000,
                    'conveyance' => 1600,
                    'medical' => 1250,
                    'special_allowance' => 2000,
                    'is_active' => true,
                ],
                // (employee, month) is unique — subMonths($i) keeps each month distinct.
                'salary' => [
                    'employee_id' => $emp,
                    'month' => now()->subMonths($i)->format('Y-m-01'),
                    'basic' => 15000,
                    'hra' => 6000,
                    'gross' => 23000,
                    'net_salary' => 21000,
                    'status' => 'draft',
                ],
            };
        }, $model);
    }

    private function makeAssetMaintenances(int $count): int
    {
        $assetIds = Asset::pluck('id')->all();
        if (empty($assetIds)) {
            return 0;
        }

        return $this->make($count, fn (int $i) => [
            'maintenance_number' => 'DAMNT-' . $this->suffix($i) . '-' . $i,
            'asset_id' => $assetIds[$i % count($assetIds)],
            'maintenance_type' => 'repair',
            'maintenance_date' => now()->subDays($i % 120)->toDateString(),
            'amount' => ($i % 50 + 1) * 200,
            'payment_method' => 'cash',
            'status' => 'draft',
            'created_by' => $this->createdBy,
        ], AssetMaintenance::class);
    }

    private function makeGstRegistrations(int $count): int
    {
        $companyIds = \App\Models\Company::pluck('id')->all();
        if (empty($companyIds)) {
            return 0;
        }

        return $this->make($count, function (int $i) use ($companyIds) {
            // 15-char GSTIN: 2 (state) + 5 (letters) + 4 (digits) + 4 (letters/digits).
            $gstin = '27ABCDE' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . 'A1Z5';

            return [
                'company_id' => $companyIds[$i % count($companyIds)],
                'gstin' => $gstin,
                'legal_name' => $this->company($i),
                'trade_name' => $this->company($i),
                'state_code' => '27',
                'state' => 'Maharashtra',
                'registration_type' => 'regular',
                'is_primary' => false,
                'is_active' => true,
            ];
        }, GstRegistration::class);
    }

    private function makeLeaveApplications(int $count): int
    {
        $employeeIds = Employee::pluck('id')->all();
        $leaveTypeIds = LeaveType::pluck('id')->all();
        if (empty($employeeIds) || empty($leaveTypeIds)) {
            return 0;
        }

        return $this->make($count, function (int $i) use ($employeeIds, $leaveTypeIds) {
            $from = now()->subDays($i % 200);

            return [
                'employee_id' => $employeeIds[$i % count($employeeIds)],
                'leave_type_id' => $leaveTypeIds[$i % count($leaveTypeIds)],
                'from_date' => $from->toDateString(),
                'to_date' => $from->copy()->addDays(2)->toDateString(),
                'days' => 3,
                'half_day' => false,
                'reason' => 'Demo leave application',
                'status' => ['pending', 'approved', 'rejected'][$i % 3],
            ];
        }, LeaveApplication::class);
    }

    private function makeBiometricPunches(int $count): int
    {
        $deviceIds = BiometricDevice::pluck('id')->all();
        $employeeIds = Employee::pluck('id')->all();

        return $this->make($count, fn (int $i) => [
            'biometric_device_id' => $deviceIds ? $deviceIds[$i % count($deviceIds)] : null,
            'employee_id' => $employeeIds ? $employeeIds[$i % count($employeeIds)] : null,
            'punched_at' => now()->subDays($i % 30)->setTime(9 + $i % 8, ($i * 7) % 60),
            'direction' => ['in', 'out'][$i % 2],
            'source' => 'device',
            'processed' => false,
        ], BiometricPunch::class);
    }

    /** Vehicle expense / fuel records — keyed to an existing vehicle. */
    private function makeVehicleDocs(int $count, string $kind): int
    {
        $vehicleIds = Vehicle::pluck('id')->all();
        if (empty($vehicleIds)) {
            return 0;
        }
        $userId = $this->createdBy;
        $model = $kind === 'fuel' ? FuelExpense::class : VehicleExpense::class;

        return $this->make($count, function (int $i) use ($kind, $vehicleIds, $userId) {
            $vehicle = $vehicleIds[$i % count($vehicleIds)];

            if ($kind === 'fuel') {
                $litres = $i % 40 + 5;

                return [
                    'expense_number' => 'DFUEL-' . $this->suffix($i) . '-' . $i,
                    'vehicle_id' => $vehicle,
                    'fuel_date' => now()->subDays($i % 120)->toDateString(),
                    'fuel_type' => 'diesel',
                    'litres' => $litres,
                    'rate_per_litre' => 95,
                    'amount' => $litres * 95,
                    'odometer' => 10000 + $i * 20,
                    'payment_method' => 'cash',
                    'status' => 'draft',
                    'created_by' => $userId,
                ];
            }

            return [
                'expense_number' => 'DVEXP-' . $this->suffix($i) . '-' . $i,
                'vehicle_id' => $vehicle,
                'expense_date' => now()->subDays($i % 120)->toDateString(),
                'expense_type' => 'service',
                'odometer' => 10000 + $i * 20,
                'amount' => ($i % 50 + 1) * 300,
                'payment_method' => 'cash',
                'status' => 'draft',
                'created_by' => $userId,
            ];
        }, $model);
    }

    /** Channel orders / listings / sync logs — keyed to a channel integration. */
    private function makeChannelChildren(int $count, string $kind): int
    {
        $channelIds = ChannelIntegration::pluck('id')->all();
        if (empty($channelIds)) {
            return 0;
        }
        $productIds = $kind === 'listing' ? Product::pluck('id')->all() : [];
        if ($kind === 'listing' && empty($productIds)) {
            return 0;
        }

        $model = match ($kind) {
            'order' => ChannelOrder::class,
            'listing' => ChannelListing::class,
            'sync' => ChannelSyncLog::class,
        };

        return $this->make($count, function (int $i) use ($kind, $channelIds, $productIds) {
            $channel = $channelIds[$i % count($channelIds)];

            return match ($kind) {
                'order' => [
                    'channel_integration_id' => $channel,
                    'external_order_id' => 'EXT-' . $this->suffix($i) . '-' . $i,
                    'external_order_number' => '#' . (1000 + $i),
                    'order_status' => 'confirmed',
                    'customer_name' => $this->person($i),
                    'customer_email' => 'buyer' . $i . '@example.com',
                    'total' => ($i % 100 + 1) * 100,
                    'currency' => 'INR',
                    'import_status' => ['pending', 'imported', 'ignored', 'failed'][$i % 4],
                    'placed_at' => now()->subDays($i % 30),
                ],
                'listing' => [
                    'channel_integration_id' => $channel,
                    'product_id' => $productIds[$i % count($productIds)],
                    'external_id' => 'LST-' . $this->suffix($i) . '-' . $i,
                    'external_sku' => 'SKU' . $i,
                    'listing_title' => 'Demo Listing ' . ($i + 1),
                    'price' => ($i % 100 + 1) * 50,
                    'status' => ['active', 'inactive'][$i % 2],
                ],
                'sync' => [
                    'channel_integration_id' => $channel,
                    'type' => ['connection', 'orders', 'inventory', 'listings'][$i % 4],
                    'direction' => ['pull', 'push'][$i % 2],
                    'status' => ['success', 'partial', 'failed'][$i % 3],
                    'processed' => $i % 50,
                    'succeeded' => $i % 40,
                    'failed' => $i % 5,
                    'message' => 'Demo sync run',
                ],
            };
        }, $model);
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
