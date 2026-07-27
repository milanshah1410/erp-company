<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Airline;
use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Attendance;
use App\Models\BankAccount;
use App\Models\Batch;
use App\Models\Beat;
use App\Models\Bom;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Broker;
use App\Models\Category;
use App\Models\ChaAgent;
use App\Models\ChannelIntegration;
use App\Models\ChannelListing;
use App\Models\ChannelOrder;
use App\Models\ChannelSyncLog;
use App\Models\ColourPrinting;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Country;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\CycleCount;
use App\Models\DeliveryRoute;
use App\Models\Department;
use App\Models\DispatchItem;
use App\Models\DispatchPlan;
use App\Models\Driver;
use App\Models\ElectricityBill;
use App\Models\EmailLog;
use App\Models\EmbroideryPayment;
use App\Models\EmbroideryVendor;
use App\Models\EmbroideryWork;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeeClaim;
use App\Models\EmployeeLoan;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FabricCutting;
use App\Models\FabricInspection;
use App\Models\FabricRoll;
use App\Models\FactoryMaintenance;
use App\Models\FollowUp;
use App\Models\FreightForwarder;
use App\Models\FuelExpense;
use App\Models\GateEntry;
use App\Models\GstRegistration;
use App\Models\HsnCode;
use App\Models\Invoice;
use App\Models\JobWork;
use App\Models\Lead;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\LoanRepayment;
use App\Models\ManufacturingOrder;
use App\Models\MarketingCampaign;
use App\Models\OvertimeRecord;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use App\Models\PieceRate;
use App\Models\Port;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\PurchaseIndent;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\RateCard;
use App\Models\Rfq;
use App\Models\Referral;
use App\Models\Salary;
use App\Models\SalaryAdvance;
use App\Models\SalaryStructure;
use App\Models\Salesman;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesReturn;
use App\Models\SerialNumber;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\ShipmentDocument;
use App\Models\ShipmentEvent;
use App\Models\ShipmentExpense;
use App\Models\ShipmentPayment;
use App\Models\ShippingLine;
use App\Models\ShrinkageRecord;
use App\Models\SmsMessage;
use App\Models\StockAdjustment;
use App\Models\StockReturn;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Tailor;
use App\Models\TailorAlteration;
use App\Models\TailorBonus;
use App\Models\TailorPayment;
use App\Models\TailorWork;
use App\Models\TdsTcsRate;
use App\Models\TransportCharge;
use App\Models\Transporter;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VendorRating;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Models\WhatsappMessage;
use App\Support\TenantContext;
use App\Support\TradeCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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
        // Organisation structure
        'companies' => 'Companies',
        'branches' => 'Branches',
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
        // Masters → Import & Export (exim masters, §24)
        'countries' => 'Countries',
        'ports' => 'Ports',
        'shipping_lines' => 'Shipping Lines',
        'airlines' => 'Airlines',
        'freight_forwarders' => 'Freight Forwarders',
        'cha_agents' => 'CHA Agents',
        'transporters' => 'Transporters',
        'overseas_suppliers' => 'Overseas Suppliers',
        'overseas_customers' => 'Overseas Customers',
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
        'purchase_indents' => 'Purchase Indents',
        'rfqs' => 'RFQs',
        'purchase_orders' => 'Purchase Orders',
        'vendor_invoices' => 'Vendor Invoices',
        'vendor_ratings' => 'Vendor Ratings',
        // Inventory
        'stock_adjustments' => 'Stock Adjustments',
        'stock_transfers' => 'Stock Transfers',
        'stock_returns' => 'Stock Returns (Inward)',
        'gate_entries' => 'Gate Entries',
        'cycle_counts' => 'Cycle Counts',
        'batches' => 'Batches',
        'serial_numbers' => 'Serial Numbers',
        // Manufacturing & Textile
        'boms' => 'Bills of Materials',
        'manufacturing_orders' => 'Manufacturing Orders',
        'colour_printings' => 'Colour Printing',
        'job_works' => 'Job Work',
        'fabric_rolls' => 'Fabric Rolls',
        'fabric_inspections' => 'Fabric Inspection',
        'shrinkage_records' => 'Shrinkage Records',
        'fabric_cuttings' => 'Fabric Cutting',
        // Tailoring
        'contractors' => 'Contractors',
        'tailors' => 'Tailors',
        'rate_cards' => 'Rate Cards',
        'piece_rates' => 'Piece Rates',
        'tailor_works' => 'Pending Work (Tailor)',
        'tailor_alterations' => 'Tailor Alterations',
        'tailor_bonuses' => 'Tailor Bonuses',
        'tailor_payments' => 'Tailor Payments',
        // Embroidery
        'embroidery_vendors' => 'Embroidery Vendors',
        'embroidery_works' => 'Pending Dispatch (Embroidery)',
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
        'dispatch_plans' => 'Dispatch Planning',
        'dispatch_pods' => 'Proof of Delivery',
        'transport_charges' => 'Transport Charges',
        // Logistics → Import & Export Logistics (§24). One shipment each brings
        // its containers, documents, timeline, expenses and settlements with it,
        // so every board in the module lights up.
        'import_shipments' => 'Import Shipments',
        'export_shipments' => 'Export Shipments',
        // The remaining boards in that group are projections of one child table
        // each, so these add rows to the shipments already on file.
        'shipment_events' => 'Shipment Timeline',
        'shipment_documents' => 'Pending Documents',
        'shipment_containers' => 'Container Status',
        'shipment_payments' => 'Pending Payments',
        'shipment_expenses' => 'Logistics Expenses',
        // E-commerce
        'channel_integrations' => 'E-commerce Integrations',
        'channel_orders' => 'Channel Orders',
        'channel_listings' => 'Channel Listings',
        'channel_sync_logs' => 'Channel Sync Logs',
        // Payments
        'payment_gateway_accounts' => 'Payment Gateways',
        'payment_transactions' => 'Payment Transactions',
        // Other operational modules
        'users' => 'Users (Staff Logins)',
        'employees' => 'Employees',
        'expenses' => 'Expenses',
    ];

    /** Quick volumes, generated inline within the request. */
    public const COUNTS = [10, 20, 50];

    /**
     * Bulk volumes offered separately in the UI. These are far too slow to run
     * inline, so DemoDataController always pushes them onto the queue.
     */
    public const BULK_COUNTS = [1_000, 100_000, 1_000_000];

    private const CHUNK = 500;

    /**
     * Every volume the generator accepts (quick + bulk).
     *
     * @return list<int>
     */
    public static function allCounts(): array
    {
        return array_merge(self::COUNTS, self::BULK_COUNTS);
    }

    /** Consignments per carrier plan when generating Proof-of-Delivery rows. */
    private const POD_DROPS_PER_PLAN = 3;

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

    /** Level-2 / level-3 name pools for the 3-level demo category tree. */
    private array $categoryLevel2 = ['Men', 'Women', 'Kids', 'Unisex'];

    private array $categoryLevel3 = ['Shirts', 'T-Shirts', 'Trousers', 'Jeans', 'Sarees', 'Kurtas', 'Sweaters', 'Jackets'];

    private array $brandNames = ['Raymond', 'Arvind', 'Vardhman', 'Bombay Dyeing', 'Fabindia', 'Peter England', 'Allen Solly', 'Van Heusen', 'Manyavar', 'Biba'];

    /** [name, short_name] pairs for units of measure. */
    private array $unitDefs = [['Pieces', 'pcs'], ['Kilogram', 'kg'], ['Metre', 'mtr'], ['Litre', 'ltr'], ['Box', 'box'], ['Dozen', 'dz'], ['Roll', 'roll'], ['Pair', 'pair'], ['Set', 'set'], ['Gram', 'gm']];

    private array $customerCategoryNames = ['Retail Walk-in', 'Wholesale', 'Distributor', 'VIP', 'Corporate', 'Online', 'Export', 'Staff', 'Reseller', 'Institutional'];

    // ---- Import & Export Logistics pools (§24) ----

    /** [alpha-2, alpha-3, name, currency, dial code, region] — real trade lanes. */
    private array $countryDefs = [
        ['IN', 'IND', 'India', 'INR', '+91', 'Asia'],
        ['CN', 'CHN', 'China', 'CNY', '+86', 'Asia'],
        ['US', 'USA', 'United States', 'USD', '+1', 'Americas'],
        ['AE', 'ARE', 'United Arab Emirates', 'AED', '+971', 'Middle East'],
        ['DE', 'DEU', 'Germany', 'EUR', '+49', 'Europe'],
        ['GB', 'GBR', 'United Kingdom', 'GBP', '+44', 'Europe'],
        ['SG', 'SGP', 'Singapore', 'SGD', '+65', 'Asia'],
        ['BD', 'BGD', 'Bangladesh', 'BDT', '+880', 'Asia'],
        ['VN', 'VNM', 'Vietnam', 'VND', '+84', 'Asia'],
        ['IT', 'ITA', 'Italy', 'EUR', '+39', 'Europe'],
        ['NL', 'NLD', 'Netherlands', 'EUR', '+31', 'Europe'],
        ['TR', 'TUR', 'Turkiye', 'TRY', '+90', 'Europe'],
        ['JP', 'JPN', 'Japan', 'JPY', '+81', 'Asia'],
        ['KR', 'KOR', 'South Korea', 'KRW', '+82', 'Asia'],
        ['MY', 'MYS', 'Malaysia', 'MYR', '+60', 'Asia'],
        ['AU', 'AUS', 'Australia', 'AUD', '+61', 'Oceania'],
        ['BR', 'BRA', 'Brazil', 'BRL', '+55', 'Americas'],
        ['SA', 'SAU', 'Saudi Arabia', 'SAR', '+966', 'Middle East'],
        ['ZA', 'ZAF', 'South Africa', 'ZAR', '+27', 'Africa'],
        ['EG', 'EGY', 'Egypt', 'EGP', '+20', 'Africa'],
    ];

    /** [city, code stem, state] for the port master. */
    private array $portDefs = [
        ['Nhava Sheva', 'NSA', 'Maharashtra'],
        ['Mundra', 'MUN', 'Gujarat'],
        ['Chennai', 'MAA', 'Tamil Nadu'],
        ['Kolkata', 'CCU', 'West Bengal'],
        ['Cochin', 'COK', 'Kerala'],
        ['Kandla', 'IXY', 'Gujarat'],
        ['Tuticorin', 'TUT', 'Tamil Nadu'],
        ['Visakhapatnam', 'VTZ', 'Andhra Pradesh'],
        ['Pipavav', 'PAV', 'Gujarat'],
        ['Hazira', 'HZA', 'Gujarat'],
    ];

    private array $carrierNames = ['Maersk', 'MSC', 'CMA CGM', 'Hapag', 'ONE Ocean', 'Evergreen', 'COSCO', 'Wan Hai', 'HMM', 'Yang Ming'];

    private array $airlineNames = ['Air India', 'Emirates', 'Lufthansa', 'Singapore', 'Qatar', 'Etihad', 'Turkish', 'Cathay', 'Korean', 'Thai'];

    private array $foreignCities = ['Shanghai', 'Dubai', 'Hamburg', 'Rotterdam', 'Singapore', 'New York', 'Istanbul', 'Ho Chi Minh City', 'Busan', 'Milan'];

    private array $currencies = ['USD', 'EUR', 'AED', 'GBP', 'SGD'];

    /** Indicative INR rate per trade currency, so the base-currency roll-ups read plausibly. */
    private array $fxRates = ['USD' => 83.2, 'EUR' => 90.4, 'AED' => 22.6, 'GBP' => 105.6, 'SGD' => 61.8];

    private array $vessels = ['Ocean Trader', 'Bay Voyager', 'Coral Star', 'Gulf Pioneer', 'Monsoon Express', 'Delta Runner', 'Harbour Queen', 'Trade Wind'];

    /** Milestones the standalone Shipment Timeline module rotates through. */
    private array $milestoneKeys = [
        'cargo_ready', 'packing_list_ready', 'stuffing_done', 'gated_in', 'departed',
        'in_transit', 'transhipment', 'arrived', 'discharged', 'duty_paid',
        'gated_out', 'delay_reported', 'document_received', 'delivered',
    ];

    /**
     * Generate $count records for each requested module.
     *
     * @param  array<int,string>  $modules
     * @return array<string,int>  module => rows created
     */
    public function generate(array $modules, int $count): array
    {
        $count = in_array($count, self::allCounts(), true) ? $count : self::COUNTS[0];
        $created = [];

        foreach ($modules as $module) {
            $created[$module] = match ($module) {
                'companies' => $this->make($count, fn (int $i) => $this->companyRow($i), Company::class),
                'branches' => $this->makeBranches($count),
                'categories' => $this->makeCategories($count),
                'brands' => $this->make($count, fn (int $i) => $this->brandRow($i), Brand::class),
                'units' => $this->make($count, fn (int $i) => $this->unitRow($i), Unit::class),
                'price_lists' => $this->makePriceLists($count),
                'customers' => $this->make($count, fn (int $i) => $this->customerRow($i), Customer::class),
                'customer_categories' => $this->make($count, fn (int $i) => $this->customerCategoryRow($i), CustomerCategory::class),
                'suppliers' => $this->make($count, fn (int $i) => $this->supplierRow($i), Supplier::class),
                'products' => $this->makeProducts($count),
                'brokers' => $this->make($count, fn (int $i) => $this->brokerRow($i), Broker::class),
                'warehouses' => $this->make($count, fn (int $i) => $this->warehouseRow($i), Warehouse::class),
                // Import & Export masters
                'countries' => $this->makeCountries($count),
                'ports' => $this->makePorts($count),
                'shipping_lines' => $this->make($count, fn (int $i) => $this->shippingLineRow($i), ShippingLine::class),
                'airlines' => $this->make($count, fn (int $i) => $this->airlineRow($i), Airline::class),
                'freight_forwarders' => $this->makeFreightForwarders($count),
                'cha_agents' => $this->makeChaAgents($count),
                'transporters' => $this->make($count, fn (int $i) => $this->transporterRow($i), Transporter::class),
                'overseas_suppliers' => $this->makeOverseasParties($count, 'supplier'),
                'overseas_customers' => $this->makeOverseasParties($count, 'customer'),
                'sales_quotations' => $this->makeSalesDocuments($count, SalesQuotation::class),
                'sales_orders' => $this->makeSalesDocuments($count, SalesOrder::class),
                'pos_sales' => $this->makeInvoices($count, 'pos'),
                'b2b_orders' => $this->makeSalesDocuments($count, SalesOrder::class, 'b2b'),
                'invoices' => $this->makeInvoices($count, 'standard'),
                'sales_returns' => $this->makeSalesReturns($count),
                'users' => $this->makeUsers($count),
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
                'purchase_indents' => $this->makePurchaseIndents($count),
                'rfqs' => $this->makeRfqs($count),
                'purchase_orders' => $this->makePurchaseOrders($count),
                'vendor_invoices' => $this->makeVendorInvoices($count),
                'vendor_ratings' => $this->makeVendorRatings($count),
                // Inventory
                'stock_adjustments' => $this->makeStockAdjustments($count),
                'stock_transfers' => $this->makeStockTransfers($count),
                'stock_returns' => $this->makeStockReturns($count),
                'gate_entries' => $this->makeGateEntries($count),
                'cycle_counts' => $this->makeCycleCounts($count),
                'batches' => $this->makeStockItems($count, 'batch'),
                'serial_numbers' => $this->makeStockItems($count, 'serial'),
                // Manufacturing & textile
                'boms' => $this->makeBoms($count),
                'manufacturing_orders' => $this->makeManufacturingOrders($count),
                'colour_printings' => $this->makeColourPrintings($count),
                'job_works' => $this->makeJobWorks($count),
                'fabric_rolls' => $this->makeStockItems($count, 'fabric_roll'),
                'fabric_inspections' => $this->makeFabricInspections($count),
                'shrinkage_records' => $this->make($count, fn (int $i) => $this->shrinkageRow($i), ShrinkageRecord::class),
                'fabric_cuttings' => $this->makeFabricCuttings($count),
                // Tailoring
                'contractors' => $this->make($count, fn (int $i) => $this->contractorRow($i), Contractor::class),
                'tailors' => $this->make($count, fn (int $i) => $this->tailorRow($i), Tailor::class),
                'rate_cards' => $this->makeRateCards($count),
                'piece_rates' => $this->makePieceRates($count),
                'tailor_works' => $this->makeTailorWorks($count),
                'tailor_alterations' => $this->make($count, fn (int $i) => $this->tailorAlterationRow($i), TailorAlteration::class),
                'tailor_bonuses' => $this->makeTailorLedger($count, 'bonus'),
                'tailor_payments' => $this->makeTailorLedger($count, 'payment'),
                // Embroidery
                'embroidery_vendors' => $this->make($count, fn (int $i) => $this->embroideryVendorRow($i), EmbroideryVendor::class),
                'embroidery_works' => $this->makeEmbroideryWorks($count),
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
                'leave_types' => $this->makeLeaveTypes($count),
                'attendance' => $this->makeEmployeeDocs($count, 'attendance'),
                'overtime_records' => $this->makeEmployeeDocs($count, 'overtime'),
                'employee_bonuses' => $this->makeEmployeeDocs($count, 'bonus'),
                'salary_advances' => $this->makeEmployeeDocs($count, 'advance'),
                'employee_loans' => $this->makeEmployeeLoans($count),
                'salary_structures' => $this->makeEmployeeDocs($count, 'structure'),
                'salaries' => $this->makeEmployeeDocs($count, 'salary'),
                'leave_applications' => $this->makeLeaveApplications($count),
                'biometric_punches' => $this->makeBiometricPunches($count),
                // Fleet & logistics
                'vehicles' => $this->make($count, fn (int $i) => $this->vehicleRow($i), Vehicle::class),
                'drivers' => $this->make($count, fn (int $i) => $this->driverRow($i), Driver::class),
                'delivery_routes' => $this->make($count, fn (int $i) => $this->deliveryRouteRow($i), DeliveryRoute::class),
                'dispatch_plans' => $this->makeDispatchPlans($count),
                'dispatch_pods' => $this->makeDispatchPods($count),
                'transport_charges' => $this->make($count, fn (int $i) => $this->transportChargeRow($i), TransportCharge::class),
                'import_shipments' => $this->makeShipments($count, 'import'),
                'export_shipments' => $this->makeShipments($count, 'export'),
                'shipment_events' => $this->makeShipmentChildren($count, 'event'),
                'shipment_documents' => $this->makeShipmentChildren($count, 'document'),
                'shipment_containers' => $this->makeShipmentChildren($count, 'container'),
                'shipment_payments' => $this->makeShipmentChildren($count, 'payment'),
                'shipment_expenses' => $this->makeShipmentChildren($count, 'expense'),
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
        // Continue $i from the model's existing row count, so re-running the
        // generator keeps numbering forward ("Raymond 11") instead of
        // restarting at 1 and creating duplicate-looking records.
        $offset = (int) $model::count();

        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use ($rowFn, $model, $offset, $start, $batch, &$made) {
                for ($i = 0; $i < $batch; $i++) {
                    $model::create($rowFn($offset + $start + $i));
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
        // Enumerate first/last pairs without repeats (first×last = 400 unique
        // names) instead of a stride that cycles after only 20.
        $pair = $i % (count($this->first) * count($this->last));
        $first = $this->first[$pair % count($this->first)];
        $last = $this->last[(intdiv($pair, count($this->first)) + $pair) % count($this->last)];

        return $first . ' ' . $last;
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

    /**
     * Generate a 3-level category tree instead of a flat list. Every 7 rows
     * form one family — 1 root ("Apparel 1"), 2 children ("Apparel 1 Men"),
     * 4 grandchildren ("Apparel 1 Men Shirts") — so any count ≥ 4 exercises
     * all three levels. Explicit slugs keep the (tenant_id, slug) unique
     * index safe across runs.
     */
    private function makeCategories(int $count): int
    {
        $made = 0;
        $rootId = null;
        $rootName = '';
        $children = []; // id => name, for the current family
        // Continue family numbering from the existing roots so re-runs
        // produce "Apparel 4", not a duplicate "Apparel 1".
        $familyOffset = (int) Category::whereNull('parent_id')->count();

        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use ($start, $batch, $familyOffset, &$made, &$rootId, &$rootName, &$children) {
                for ($k = 0; $k < $batch; $k++) {
                    $i = $start + $k;
                    $slot = $i % 7;

                    if ($slot === 0 || $rootId === null) {
                        $family = intdiv($i, 7) + $familyOffset;
                        $rootName = $this->categoryNames[$family % count($this->categoryNames)] . ' ' . ($family + 1);
                        $root = Category::create($this->categoryPayload($rootName, null, $i));
                        $rootId = $root->id;
                        $children = [];
                    } elseif ($slot <= 2 || empty($children)) {
                        $name = $rootName . ' ' . $this->categoryLevel2[$i % count($this->categoryLevel2)];
                        $child = Category::create($this->categoryPayload($name, $rootId, $i));
                        $children[$child->id] = $name;
                    } else {
                        $parentId = array_keys($children)[$i % count($children)];
                        $name = $children[$parentId] . ' ' . $this->categoryLevel3[$i % count($this->categoryLevel3)];
                        Category::create($this->categoryPayload($name, $parentId, $i));
                    }
                    $made++;
                }
            });
        }

        return $made;
    }

    private function categoryPayload(string $name, ?int $parentId, int $i): array
    {
        return [
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . strtolower($this->suffix($i)),
            'description' => 'Demo category for ' . $name,
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
        // Name carries the (tenant_id, name) unique index — the time-based
        // suffix keeps it distinct across runs, not just within one.
        $name = $base . ' ' . ($i + 1) . ' ' . $this->suffix($i);

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

    private function companyRow(int $i): array
    {
        $name = $this->company($i);
        $city = $this->cities[$i % count($this->cities)];

        return [
            'code' => 'DCO-' . $this->suffix($i) . '-' . $i,
            'name' => $name,
            'legal_name' => $name . ' Pvt. Ltd.',
            'email' => 'company' . $i . '.' . strtolower($this->suffix($i)) . '@example.com',
            'phone' => $this->phone($i),
            'address' => ($i + 1) . ', ' . $this->last[$i % count($this->last)] . ' Complex',
            'city' => $city,
            'state' => $this->states[$i % count($this->states)],
            'country' => 'India',
            'pincode' => (string) (380000 + ($i * 7) % 99999),
            'financial_year_start_month' => 4,
            'currency_code' => 'INR',
            'currency_symbol' => '₹',
            'is_active' => true,
        ];
    }

    /** Branch rows hang off existing companies; returns 0 if none exist yet. */
    private function makeBranches(int $count): int
    {
        $companyIds = Company::pluck('id')->all();
        if (empty($companyIds)) {
            return 0;
        }

        return $this->make($count, function (int $i) use ($companyIds) {
            $city = $this->cities[$i % count($this->cities)];

            return [
                'company_id' => $companyIds[$i % count($companyIds)],
                'code' => 'DBR-' . $this->suffix($i) . '-' . $i,
                'name' => $city . ' Branch ' . ($i + 1),
                'address' => ($i + 1) . ', ' . $this->last[($i * 3) % count($this->last)] . ' Road',
                'city' => $city,
                'state' => $this->states[$i % count($this->states)],
                'country' => 'India',
                'pincode' => (string) (380000 + ($i * 11) % 99999),
                'phone' => $this->phone($i),
                'email' => 'branch' . $i . '.' . strtolower($this->suffix($i)) . '@example.com',
                'is_active' => true,
            ];
        }, Branch::class);
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
        // B2B orders: mostly awaiting approval (the queue's working state), plus
        // some already approved (confirmed) / rejected (cancelled) for history.
        $statuses = $isB2b
            ? ['pending_approval', 'pending_approval', 'confirmed', 'cancelled']
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
            // (tenant_id, name) is unique — suffix keeps re-runs collision-free.
            'name' => 'Demo Bank A/C ' . ($i + 1) . ' ' . $this->suffix($i),
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
            // (tenant_id, name) is unique — suffix keeps re-runs collision-free.
            'name' => 'Demo Expense Category ' . ($i + 1) . ' ' . $this->suffix($i),
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

    /**
     * Leave types are a small fixed vocabulary — create only the ones that
     * don't exist yet (max 5), never duplicates. $count only caps the batch.
     */
    private function makeLeaveTypes(int $count): int
    {
        $pool = ['Casual Leave', 'Sick Leave', 'Earned Leave', 'Maternity Leave', 'Comp Off'];
        $existing = LeaveType::pluck('name')->flip();

        $made = 0;
        foreach ($pool as $i => $name) {
            if ($made >= $count || isset($existing[$name])) {
                continue;
            }
            LeaveType::create([
                'code' => 'DLT-' . $this->suffix($i) . '-' . $i,
                'name' => $name,
                'annual_quota' => [6, 12, 15][$i % 3],
                'is_paid' => true,
                'carry_forward' => $i % 2 === 0,
                'is_active' => true,
            ]);
            $made++;
        }

        return $made;
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

    /**
     * Build 1–3 purchase line items for product-based purchase documents.
     * Unit cost is derived from the selling price (≈70%) so margins look sane.
     *
     * @return array{lines: array<int,array>, subtotal: float, tax: float, total: float}
     */
    private function buildPurchaseLines(int $i, $products, int $productCount): array
    {
        $lines = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $lineCount = 1 + ($i % 3);

        for ($l = 0; $l < $lineCount; $l++) {
            $product = $products[($i * 3 + $l * 11) % $productCount];
            $qty = 5 + (($i + $l) * 7) % 46;
            $unitCost = round((float) $product->selling_price * 0.7, 2) ?: 100.0;
            $taxPercent = (float) $product->tax_percent;
            $amount = round($qty * $unitCost, 2);
            $tax = round($amount * $taxPercent / 100, 2);

            $lines[] = [
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'tax_percent' => $taxPercent,
                'tax_amount' => $tax,
                'line_total' => round($amount + $tax, 2),
            ];
            $subtotal += $amount;
            $taxTotal += $tax;
        }

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'tax' => round($taxTotal, 2),
            'total' => round($subtotal + $taxTotal, 2),
        ];
    }

    /** Purchase indents with 1–3 requested items; mostly awaiting approval. */
    private function makePurchaseIndents(int $count): int
    {
        $products = Product::select('id', 'selling_price', 'tax_percent')->get();
        if ($products->isEmpty()) {
            return 0;
        }
        $warehouseIds = Warehouse::pluck('id')->all() ?: [null];
        $departmentIds = Department::pluck('id')->all() ?: [null];
        $productCount = $products->count();
        $statuses = ['pending_approval', 'pending_approval', 'approved', 'draft', 'rejected'];

        return $this->makeChunked($count, function (int $i) use ($products, $productCount, $warehouseIds, $departmentIds, $statuses) {
            $status = $statuses[$i % count($statuses)];
            $indent = PurchaseIndent::create([
                'indent_number' => 'DPI-' . $this->suffix($i) . '-' . $i,
                'department_id' => $departmentIds[$i % count($departmentIds)],
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'status' => $status,
                'priority' => ['low', 'normal', 'normal', 'high', 'urgent'][$i % 5],
                'indent_date' => now()->subDays($i % 60)->toDateString(),
                'required_date' => now()->addDays(7 + $i % 21)->toDateString(),
                'notes' => 'Demo purchase indent',
                'rejection_reason' => $status === 'rejected' ? 'Budget not available this month' : null,
                'created_by' => $this->createdBy,
            ]);
            $built = $this->buildPurchaseLines($i, $products, $productCount);
            $indent->items()->createMany(array_map(fn ($l) => [
                'product_id' => $l['product_id'],
                'quantity' => $l['quantity'],
                'estimated_cost' => $l['unit_cost'],
            ], $built['lines']));
        });
    }

    /** RFQs with 1–3 items and 2–3 invited suppliers (some responded with quotes). */
    private function makeRfqs(int $count): int
    {
        $products = Product::select('id', 'selling_price', 'tax_percent')->get();
        $supplierIds = Supplier::pluck('id')->all();
        if ($products->isEmpty() || count($supplierIds) < 2) {
            return 0;
        }
        $warehouseIds = Warehouse::pluck('id')->all() ?: [null];
        $productCount = $products->count();
        $statuses = ['draft', 'sent', 'sent', 'awarded', 'closed'];

        return $this->makeChunked($count, function (int $i) use ($products, $productCount, $supplierIds, $warehouseIds, $statuses) {
            $status = $statuses[$i % count($statuses)];
            $built = $this->buildPurchaseLines($i, $products, $productCount);

            // 2–3 invited suppliers; past-draft RFQs have some responses.
            $invited = [];
            $inviteCount = 2 + ($i % 2);
            for ($s = 0; $s < $inviteCount; $s++) {
                $invited[] = $supplierIds[($i + $s * 3) % count($supplierIds)];
            }
            $invited = array_values(array_unique($invited));

            $rfq = Rfq::create([
                'rfq_number' => 'DRFQ-' . $this->suffix($i) . '-' . $i,
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'status' => $status,
                'rfq_date' => now()->subDays($i % 45)->toDateString(),
                'response_deadline' => now()->addDays(5 + $i % 10)->toDateString(),
                'awarded_supplier_id' => in_array($status, ['awarded', 'closed'], true) ? $invited[0] : null,
                'notes' => 'Demo RFQ',
                'created_by' => $this->createdBy,
            ]);
            $rfq->items()->createMany(array_map(fn ($l) => [
                'product_id' => $l['product_id'],
                'quantity' => $l['quantity'],
            ], $built['lines']));

            foreach ($invited as $idx => $supplierId) {
                $responded = $status !== 'draft' && ($idx === 0 || ($i + $idx) % 2 === 0);
                $rfq->suppliers()->create([
                    'supplier_id' => $supplierId,
                    'status' => $responded ? 'responded' : 'invited',
                    'quoted_total' => $responded ? round($built['total'] * (0.92 + 0.05 * $idx), 2) : 0,
                    'lead_time_days' => $responded ? 5 + ($idx * 3) : null,
                    'responded_at' => $responded ? now()->subDays($i % 10) : null,
                ]);
            }
        });
    }

    /** Purchase orders with 1–3 line items across the status lifecycle. */
    private function makePurchaseOrders(int $count): int
    {
        $products = Product::select('id', 'selling_price', 'tax_percent')->get();
        $supplierIds = Supplier::pluck('id')->all();
        $warehouseIds = Warehouse::pluck('id')->all();
        if ($products->isEmpty() || empty($supplierIds) || empty($warehouseIds)) {
            return 0;
        }
        $productCount = $products->count();
        $statuses = ['draft', 'pending_approval', 'approved', 'partially_received', 'received'];

        return $this->makeChunked($count, function (int $i) use ($products, $productCount, $supplierIds, $warehouseIds, $statuses) {
            $status = $statuses[$i % count($statuses)];
            $built = $this->buildPurchaseLines($i, $products, $productCount);

            $po = PurchaseOrder::create([
                'po_number' => 'DPO-' . $this->suffix($i) . '-' . $i,
                'supplier_id' => $supplierIds[$i % count($supplierIds)],
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'status' => $status,
                'order_date' => now()->subDays($i % 90)->toDateString(),
                'expected_date' => now()->addDays(7 + $i % 14)->toDateString(),
                'subtotal' => $built['subtotal'],
                'tax_amount' => $built['tax'],
                'total' => $built['total'],
                'notes' => 'Demo purchase order',
                'created_by' => $this->createdBy,
            ]);
            $received = ['received' => 1.0, 'partially_received' => 0.5][$status] ?? 0.0;
            $po->items()->createMany(array_map(fn ($l) => $l + [
                'received_quantity' => round($l['quantity'] * $received, 3),
            ], $built['lines']));
        });
    }

    /**
     * Vendor invoices (purchase invoices) billed against existing POs with
     * items — one invoice per PO round-robin; returns 0 if no POs exist yet.
     */
    private function makeVendorInvoices(int $count): int
    {
        $pos = PurchaseOrder::with('items')->has('items')->latest('id')->limit(200)->get()->values();
        if ($pos->isEmpty()) {
            return 0;
        }

        return $this->makeChunked($count, function (int $i) use ($pos) {
            $po = $pos[$i % $pos->count()];
            $status = ['pending', 'pending', 'approved'][$i % 3];

            $invoice = PurchaseInvoice::create([
                'invoice_number' => 'DVI-' . $this->suffix($i) . '-' . $i,
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'invoice_date' => now()->subDays($i % 30)->toDateString(),
                'due_date' => now()->addDays(15 + $i % 30)->toDateString(),
                'subtotal' => $po->subtotal,
                'tax_amount' => $po->tax_amount,
                'total' => $po->total,
                'status' => $status,
                'match_status' => $i % 5 === 4 ? 'exception' : 'matched',
                'notes' => 'Demo vendor invoice',
                'created_by' => $this->createdBy,
            ]);
            $invoice->items()->createMany($po->items->map(fn ($item) => [
                'purchase_item_id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
                'unit_cost' => $item->unit_cost,
                'tax_percent' => $item->tax_percent,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ])->all());
        });
    }

    /** Run $rowFn once per record inside chunked transactions; returns rows made. */
    private function makeChunked(int $count, callable $rowFn): int
    {
        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use ($rowFn, $start, $batch, &$made) {
                for ($k = 0; $k < $batch; $k++) {
                    $rowFn($start + $k);
                    $made++;
                }
            });
        }

        return $made;
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

    /**
     * Inter-warehouse transfers with 1–3 item lines, cycling draft →
     * in_transit → received → cancelled. from/to are always different
     * warehouses, so needs at least two of them plus products. Like the other
     * inventory generators this writes documents only — it does not post stock
     * movements, so warehouse balances stay untouched.
     */
    private function makeStockTransfers(int $count): int
    {
        $warehouseIds = Warehouse::pluck('id')->all();
        $productIds = Product::pluck('id')->all();
        if (count($warehouseIds) < 2 || empty($productIds)) {
            return 0;
        }
        $userId = $this->createdBy;
        $warehouseCount = count($warehouseIds);
        $productCount = count($productIds);
        $offset = (int) StockTransfer::count();

        return $this->makeChunked($count, function (int $k) use (
            $warehouseIds, $warehouseCount, $productIds, $productCount, $userId, $offset
        ) {
            $i = $offset + $k;
            $status = ['draft', 'in_transit', 'in_transit', 'received', 'cancelled'][$i % 5];
            $dispatched = in_array($status, ['in_transit', 'received'], true);
            $date = now()->subDays($i % 90);

            $transfer = StockTransfer::create([
                'transfer_number' => 'DTRF-' . $this->suffix($i) . '-' . $i,
                'from_warehouse_id' => $warehouseIds[$i % $warehouseCount],
                // +1 (mod) guarantees a different destination warehouse.
                'to_warehouse_id' => $warehouseIds[($i + 1) % $warehouseCount],
                'status' => $status,
                'transfer_date' => $date->toDateString(),
                'received_date' => $status === 'received' ? $date->copy()->addDays(2)->toDateString() : null,
                'notes' => 'Demo stock transfer',
                'created_by' => $userId,
                'dispatched_by' => $dispatched ? $userId : null,
                'received_by' => $status === 'received' ? $userId : null,
            ]);

            $lines = [];
            for ($l = 0; $l < ($i % 3) + 1; $l++) {
                $lines[] = [
                    'product_id' => $productIds[($i + $l) % $productCount],
                    'quantity' => ($i + $l) % 20 + 1,
                    'unit_cost' => (($i + $l) % 50 + 5) * 10,
                ];
            }
            $transfer->items()->createMany($lines);
        });
    }

    /**
     * Inward stock returns — goods coming back into a warehouse, either a
     * customer return or a vendor return (which carries a debit note number),
     * cycling draft → approved → cancelled with per-line return reasons. Needs
     * warehouses and products; the customer/supplier reference is filled when
     * those masters exist.
     */
    private function makeStockReturns(int $count): int
    {
        $warehouseIds = Warehouse::pluck('id')->all();
        $productIds = Product::pluck('id')->all();
        if (empty($warehouseIds) || empty($productIds)) {
            return 0;
        }
        $customerIds = Customer::pluck('id')->all();
        $supplierIds = Supplier::pluck('id')->all();
        $reasons = ['Damaged in transit', 'Wrong size supplied', 'Quality rejected', 'Excess quantity', 'Customer changed mind'];
        $userId = $this->createdBy;
        $productCount = count($productIds);
        $offset = (int) StockReturn::count();

        return $this->makeChunked($count, function (int $k) use (
            $warehouseIds, $productIds, $productCount, $customerIds, $supplierIds, $reasons, $userId, $offset
        ) {
            $i = $offset + $k;
            // Vendor returns only make sense when there are suppliers to cite.
            $type = ($i % 3 === 2 && $supplierIds) ? 'vendor_return' : ($customerIds ? 'customer_return' : 'other');
            $status = ['draft', 'approved', 'approved', 'cancelled'][$i % 4];
            $date = now()->subDays($i % 120);

            $return = StockReturn::create([
                'return_number' => 'DSR-' . $this->suffix($i) . '-' . $i,
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'return_type' => $type,
                'customer_id' => $type === 'customer_return' ? $customerIds[$i % count($customerIds)] : null,
                'supplier_id' => $type === 'vendor_return' ? $supplierIds[$i % count($supplierIds)] : null,
                'debit_note_number' => $type === 'vendor_return' ? 'DDN-' . $this->suffix($i) . '-' . $i : null,
                'status' => $status,
                'return_date' => $date->toDateString(),
                'notes' => 'Demo inward stock return',
                'created_by' => $userId,
                'approved_by' => $status === 'approved' ? $userId : null,
                'approved_at' => $status === 'approved' ? $date->copy()->addDay() : null,
            ]);

            $lines = [];
            for ($l = 0; $l < ($i % 3) + 1; $l++) {
                $lines[] = [
                    'product_id' => $productIds[($i + $l) % $productCount],
                    'quantity' => ($i + $l) % 10 + 1,
                    'unit_cost' => (($i + $l) % 50 + 5) * 10,
                    'reason' => $reasons[($i + $l) % count($reasons)],
                ];
            }
            $return->items()->createMany($lines);
        });
    }

    /**
     * Cycle counts (physical stock verification): 2–5 counted products per
     * sheet with the system quantity, the counted quantity and the resulting
     * variance — some short, some over, most matching — across draft →
     * completed → cancelled. Needs warehouses and products.
     */
    private function makeCycleCounts(int $count): int
    {
        $warehouseIds = Warehouse::pluck('id')->all();
        $productIds = Product::pluck('id')->all();
        if (empty($warehouseIds) || empty($productIds)) {
            return 0;
        }
        $userId = $this->createdBy;
        $productCount = count($productIds);
        $offset = (int) CycleCount::count();

        return $this->makeChunked($count, function (int $k) use ($warehouseIds, $productIds, $productCount, $userId, $offset) {
            $i = $offset + $k;
            $status = ['draft', 'completed', 'completed', 'cancelled'][$i % 4];
            $date = now()->subDays($i % 90);

            $sheet = CycleCount::create([
                'count_number' => 'DCC-' . $this->suffix($i) . '-' . $i,
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'status' => $status,
                'count_date' => $date->toDateString(),
                'notes' => 'Demo cycle count',
                'created_by' => $userId,
                'completed_by' => $status === 'completed' ? $userId : null,
                'completed_at' => $status === 'completed' ? $date->copy()->addHours(6) : null,
            ]);

            $lines = [];
            for ($l = 0; $l < ($i % 4) + 2; $l++) {
                $systemQty = ($i + $l) % 40 + 10;
                // Every third line is off by a little — the point of counting.
                $delta = match (($i + $l) % 3) {
                    0 => 0,
                    1 => -(($i + $l) % 3 + 1),
                    default => ($i + $l) % 2 + 1,
                };
                $countedQty = $status === 'draft' ? 0 : $systemQty + $delta;
                $lines[] = [
                    'product_id' => $productIds[($i + $l) % $productCount],
                    'system_quantity' => $systemQty,
                    'counted_quantity' => $countedQty,
                    'variance' => $status === 'draft' ? 0 : $countedQty - $systemQty,
                    'unit_cost' => (($i + $l) % 50 + 5) * 10,
                    'notes' => $delta === 0 ? null : ($delta < 0 ? 'Short on shelf' : 'Extra found in bin'),
                ];
            }
            $sheet->items()->createMany($lines);
        });
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

    /**
     * Bills of Materials: a finished-good header plus 2–4 component lines drawn
     * from the other products, with the header's material_cost rolled up from
     * the lines. Needs at least two products; returns 0 otherwise.
     */
    private function makeBoms(int $count): int
    {
        $products = Product::select('id', 'name', 'cost_price')->get();
        if ($products->count() < 2) {
            return 0;
        }
        $userId = $this->createdBy;
        $n = $products->count();
        $offset = (int) Bom::count();

        return $this->makeChunked($count, function (int $k) use ($products, $n, $userId, $offset) {
            $i = $offset + $k;
            $output = $products[$i % $n];
            $lines = [];
            $materialCost = 0.0;

            for ($c = 1; $c <= ($i % 3) + 2; $c++) {
                $component = $products[($i + $c) % $n];
                $quantity = round(($c % 4 + 1) * 0.5, 3);
                $unitCost = (float) ($component->cost_price ?: 50);
                $totalCost = round($quantity * $unitCost, 2);
                $materialCost += $totalCost;
                $lines[] = [
                    'product_id' => $component->id,
                    'quantity' => $quantity,
                    'wastage_percent' => [0, 2, 5][$c % 3],
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'remarks' => 'Demo component line',
                ];
            }

            $bom = Bom::create([
                'bom_number' => 'DBOM-' . $this->suffix($i) . '-' . $i,
                'name' => $output->name . ' — Recipe ' . ($i + 1),
                'product_id' => $output->id,
                'output_quantity' => 1,
                // Only the first BOM per product is the default one.
                'is_default' => $i < $n,
                'is_active' => true,
                'material_cost' => round($materialCost, 2),
                'notes' => 'Demo bill of materials',
                'created_by' => $userId,
            ]);
            $bom->items()->createMany($lines);
        });
    }

    /**
     * Manufacturing orders across draft → in_progress → completed → cancelled,
     * each with its material lines and the full production stage pipeline
     * (ManufacturingOrder::STAGES) progressed to match the header. Needs
     * products and warehouses; a BOM is linked when one exists for the product.
     */
    private function makeManufacturingOrders(int $count): int
    {
        $products = Product::select('id', 'cost_price')->get();
        $warehouseIds = Warehouse::pluck('id')->all();
        if ($products->isEmpty() || empty($warehouseIds)) {
            return 0;
        }
        $bomByProduct = Bom::query()->select('id', 'product_id')->get()->keyBy('product_id');
        $employeeIds = Employee::pluck('id')->all();
        $userId = $this->createdBy;
        $n = $products->count();
        $stages = ManufacturingOrder::STAGES;
        $stageCount = count($stages);
        $offset = (int) ManufacturingOrder::count();

        return $this->makeChunked($count, function (int $k) use (
            $products, $n, $warehouseIds, $bomByProduct, $employeeIds, $userId, $stages, $stageCount, $offset
        ) {
            $i = $offset + $k;
            $product = $products[$i % $n];
            $status = ['draft', 'in_progress', 'in_progress', 'completed', 'cancelled'][$i % 5];
            $started = in_array($status, ['in_progress', 'completed'], true);
            $quantity = ($i % 10 + 1) * 25;
            $orderDate = now()->subDays($i % 90);

            // Stage costs: only stages actually worked on carry one.
            $doneStages = match ($status) {
                'completed' => $stageCount,
                'in_progress' => intdiv($stageCount, 2),
                default => 0,
            };
            $stageCost = 0.0;
            $materialCost = 0.0;

            $materials = [];
            for ($m = 1; $m <= ($i % 3) + 1; $m++) {
                $component = $products[($i + $m) % $n];
                $qty = round($quantity * 0.5 * $m, 3);
                $unitCost = (float) ($component->cost_price ?: 50);
                $lineCost = round($qty * $unitCost, 2);
                $materialCost += $lineCost;
                $materials[] = [
                    'product_id' => $component->id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                    'consumed_quantity' => $started ? $qty : 0,
                    'remarks' => 'Demo material line',
                ];
            }

            $stageRows = [];
            $seq = 0;
            foreach ($stages as $key => $name) {
                $seq++;
                $stageStatus = match (true) {
                    $seq <= $doneStages => 'completed',
                    $seq === $doneStages + 1 && $status === 'in_progress' => 'in_progress',
                    default => 'pending',
                };
                $cost = $stageStatus === 'pending' ? 0 : ($seq % 5 + 1) * 150;
                $stageCost += $cost;
                $stageRows[] = [
                    'stage_key' => $key,
                    'name' => $name,
                    'sequence' => $seq,
                    'assigned_employee_id' => $employeeIds ? $employeeIds[($i + $seq) % count($employeeIds)] : null,
                    'start_date' => $stageStatus === 'pending' ? null : $orderDate->copy()->addDays($seq)->toDateString(),
                    'end_date' => $stageStatus === 'completed' ? $orderDate->copy()->addDays($seq + 1)->toDateString() : null,
                    'status' => $stageStatus,
                    'cost' => $cost,
                ];
            }

            $order = ManufacturingOrder::create([
                'mo_number' => 'DMO-' . $this->suffix($i) . '-' . $i,
                'product_id' => $product->id,
                'bom_id' => $bomByProduct[$product->id]->id ?? null,
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'quantity_to_produce' => $quantity,
                'quantity_produced' => $status === 'completed' ? $quantity : ($status === 'in_progress' ? round($quantity / 2, 3) : 0),
                'status' => $status,
                'order_date' => $orderDate->toDateString(),
                'expected_completion_date' => $orderDate->copy()->addDays(15)->toDateString(),
                'material_cost' => round($materialCost, 2),
                'stage_cost' => $stageCost,
                'total_cost' => round($materialCost + $stageCost, 2),
                'materials_consumed' => $started,
                'finished_received' => $status === 'completed',
                'started_at' => $started ? $orderDate->copy()->addDay() : null,
                'completed_at' => $status === 'completed' ? $orderDate->copy()->addDays(12) : null,
                'notes' => 'Demo manufacturing order',
                'created_by' => $userId,
            ]);
            $order->materials()->createMany($materials);
            $order->stages()->createMany($stageRows);
        });
    }

    /**
     * Job work (out-sourced processing): a supplier + warehouse header with 1–3
     * issued item lines, walked through draft → issued → partial → completed →
     * closed → cancelled with received/damage quantities to match. Needs
     * suppliers, warehouses and products.
     */
    private function makeJobWorks(int $count): int
    {
        $supplierIds = Supplier::pluck('id')->all();
        $warehouseIds = Warehouse::pluck('id')->all();
        $products = Product::select('id', 'cost_price')->get();
        if (empty($supplierIds) || empty($warehouseIds) || $products->isEmpty()) {
            return 0;
        }
        $userId = $this->createdBy;
        $n = $products->count();
        $offset = (int) JobWork::count();

        return $this->makeChunked($count, function (int $k) use ($supplierIds, $warehouseIds, $products, $n, $userId, $offset) {
            $i = $offset + $k;
            $status = ['draft', 'issued', 'partial', 'completed', 'closed', 'cancelled'][$i % 6];
            $issued = ! in_array($status, ['draft', 'cancelled'], true);
            $finished = in_array($status, ['completed', 'closed'], true);
            $date = now()->subDays($i % 120);

            $jobWork = JobWork::create([
                'job_work_number' => 'DJW-' . $this->suffix($i) . '-' . $i,
                'supplier_id' => $supplierIds[$i % count($supplierIds)],
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'status' => $status,
                'job_type' => ['stitching', 'printing', 'embroidery', 'washing', 'packing'][$i % 5],
                'job_work_date' => $date->toDateString(),
                'expected_date' => $date->copy()->addDays(10)->toDateString(),
                'issued_date' => $issued ? $date->copy()->addDay()->toDateString() : null,
                'completed_date' => $finished ? $date->copy()->addDays(9)->toDateString() : null,
                'notes' => 'Demo job work',
                'created_by' => $userId,
                'issued_by' => $issued ? $userId : null,
                'closed_by' => $status === 'closed' ? $userId : null,
            ]);

            $lines = [];
            for ($l = 0; $l < ($i % 3) + 1; $l++) {
                $component = $products[($i + $l) % $n];
                $finishedProduct = $products[($i + $l + 1) % $n];
                $issuedQty = ($i % 20 + 5) * 10;
                // Received quantity tracks the header's state; the shortfall is
                // split between damage and shortage so the item maths balances.
                $receivedQty = match ($status) {
                    'completed', 'closed' => $issuedQty - 2,
                    'partial' => round($issuedQty / 2, 3),
                    default => 0,
                };
                $lines[] = [
                    'product_id' => $component->id,
                    'finished_product_id' => $finishedProduct->id,
                    'issued_qty' => $issuedQty,
                    'received_qty' => $receivedQty,
                    'damage_qty' => $finished ? 1 : 0,
                    'shortage_qty' => $finished ? 1 : 0,
                    'unit_cost' => (float) ($component->cost_price ?: 50),
                    'finished_unit_cost' => round((float) ($finishedProduct->cost_price ?: 80) * 1.1, 4),
                ];
            }
            $jobWork->items()->createMany($lines);
        });
    }

    /**
     * 4-point-system fabric inspections: 1–3 defect lines whose penalty points
     * roll up into the header's score, grade and pass/reject result. Needs
     * fabric rolls; returns 0 if none exist yet.
     */
    private function makeFabricInspections(int $count): int
    {
        $rolls = FabricRoll::select('id', 'current_length', 'width')->get();
        if ($rolls->isEmpty()) {
            return 0;
        }
        $defectTypes = ['Slub', 'Hole', 'Stain', 'Weft bar', 'Missing yarn', 'Colour variation', 'Crease mark'];
        $userId = $this->createdBy;
        $n = $rolls->count();
        $offset = (int) FabricInspection::count();

        return $this->makeChunked($count, function (int $k) use ($rolls, $n, $defectTypes, $userId, $offset) {
            $i = $offset + $k;
            $roll = $rolls[$i % $n];
            $length = (float) ($roll->current_length ?: 100);
            $width = (float) ($roll->width ?: 44);

            $defects = [];
            $totalPoints = 0.0;
            for ($d = 0; $d < ($i % 3) + 1; $d++) {
                $severity = ['minor', 'major', 'critical'][($i + $d) % 3];
                $points = ['minor' => 1, 'major' => 2, 'critical' => 4][$severity];
                // Scale occurrences with the roll length, otherwise a long roll
                // always scores near zero and every inspection grades A.
                $occurrences = (($i + $d) % 4 + 1) * max(1, (int) round($length / 45));
                $totalPoints += $points * $occurrences;
                $defects[] = [
                    'defect_type' => $defectTypes[($i + $d) % count($defectTypes)],
                    'severity' => $severity,
                    'size_range' => ['under 3 inch', '3-6 inch', '6-9 inch', 'over 9 inch'][($i + $d) % 4],
                    'points' => $points,
                    'count' => $occurrences,
                ];
            }

            // 4-point score: points per 100 square metres of inspected fabric.
            $sqm = max(0.01, $length * ($width * 0.0254));
            $score = round($totalPoints * 100 / $sqm, 2);
            [$grade, $result] = match (true) {
                $score <= 20 => ['A', 'pass'],
                $score <= 30 => ['B', 'pass'],
                default => ['C', 'reject'],
            };

            $inspection = FabricInspection::create([
                'inspection_number' => 'DFI-' . $this->suffix($i) . '-' . $i,
                'fabric_roll_id' => $roll->id,
                'inspected_length' => $length,
                'inspected_width' => $width,
                'total_points' => $totalPoints,
                'points_per_100sqm' => $score,
                'grade' => $grade,
                'result' => $result,
                'inspector' => $this->person($i),
                'inspection_date' => now()->subDays($i % 90)->toDateString(),
                'notes' => 'Demo 4-point inspection',
                'created_by' => $userId,
            ]);
            $inspection->defects()->createMany($defects);
        });
    }

    /**
     * Fabric cutting runs. The lengths balance — used = consumed + loss +
     * leftover — so the cutting report adds up. Needs fabric rolls and
     * warehouses.
     */
    private function makeFabricCuttings(int $count): int
    {
        $rolls = FabricRoll::select('id', 'current_length')->get();
        $warehouseIds = Warehouse::pluck('id')->all();
        if ($rolls->isEmpty() || empty($warehouseIds)) {
            return 0;
        }
        $userId = $this->createdBy;
        $n = $rolls->count();

        // make() already continues $i from the existing row count.
        return $this->make($count, function (int $i) use ($rolls, $n, $warehouseIds, $userId) {
            $roll = $rolls[$i % $n];
            $used = round(min((float) ($roll->current_length ?: 100), ($i % 8 + 3) * 10), 3);
            $loss = round($used * 0.03, 3);
            $leftover = round($used * 0.07, 3);
            $consumed = round($used - $loss - $leftover, 3);

            return [
                'cutting_number' => 'DFC-' . $this->suffix($i) . '-' . $i,
                'fabric_roll_id' => $roll->id,
                'warehouse_id' => $warehouseIds[$i % count($warehouseIds)],
                'cut_date' => now()->subDays($i % 90)->toDateString(),
                'length_used' => $used,
                'length_consumed' => $consumed,
                'cutting_loss' => $loss,
                'leftover_length' => $leftover,
                'pieces_produced' => (int) floor($consumed * 2),
                'notes' => 'Demo cutting run',
                'created_by' => $userId,
            ];
        }, FabricCutting::class);
    }

    /**
     * Extra piece-rate operations on the existing rate cards (the cards
     * themselves come with a starter set — see makeRateCards). Sequence
     * continues from what each card already has, so re-runs keep extending
     * rather than clashing. Needs rate cards; returns 0 if none exist.
     */
    private function makePieceRates(int $count): int
    {
        $cardIds = RateCard::pluck('id')->all();
        if (empty($cardIds)) {
            return 0;
        }
        $operations = ['Cutting', 'Stitching', 'Finishing', 'Overlock', 'Hemming', 'Button Fixing', 'Ironing', 'Packing', 'Embroidery Prep', 'Quality Check'];
        // Where each card's numbering currently ends.
        $nextSequence = PieceRate::query()
            ->selectRaw('rate_card_id, max(sequence) as max_sequence')
            ->groupBy('rate_card_id')
            ->pluck('max_sequence', 'rate_card_id')
            ->all();

        return $this->make($count, function (int $i) use ($cardIds, $operations, &$nextSequence) {
            $cardId = $cardIds[$i % count($cardIds)];
            $sequence = ($nextSequence[$cardId] ?? 0) + 1;
            $nextSequence[$cardId] = $sequence;
            // Past the pool, operations repeat with a pass number appended.
            $operation = $operations[($sequence - 1) % count($operations)];
            $pass = intdiv($sequence - 1, count($operations)) + 1;

            return [
                'rate_card_id' => $cardId,
                'operation' => $pass > 1 ? $operation . ' (Pass ' . $pass . ')' : $operation,
                'rate' => ($sequence % 8 + 1) * 15,
                'sequence' => $sequence,
            ];
        }, PieceRate::class);
    }

    /**
     * Tailor work orders. Weighted towards open work so the "Pending Work"
     * screen (status pending/partial) has a queue, with completed orders — and
     * their completion entries, which drive the tailor ledger — for the
     * "Completed Work" screen. Needs tailors and products.
     */
    private function makeTailorWorks(int $count): int
    {
        $tailorIds = Tailor::pluck('id')->all();
        $productIds = Product::pluck('id')->all();
        if (empty($tailorIds) || empty($productIds)) {
            return 0;
        }
        $rateCards = RateCard::query()->select('id', 'product_id', 'standard_rate')->get()->keyBy('product_id');
        $userId = $this->createdBy;
        $offset = (int) TailorWork::count();

        return $this->makeChunked($count, function (int $k) use ($tailorIds, $productIds, $rateCards, $userId, $offset) {
            $i = $offset + $k;
            $tailorId = $tailorIds[$i % count($tailorIds)];
            $productId = $productIds[$i % count($productIds)];
            $card = $rateCards[$productId] ?? null;
            $rate = (float) ($card->standard_rate ?? (($i % 20 + 5) * 5));
            $status = ['pending', 'pending', 'partial', 'completed', 'cancelled'][$i % 5];
            $issuedQty = ($i % 15 + 2) * 10;
            $completedQty = match ($status) {
                'completed' => $issuedQty,
                'partial' => round($issuedQty / 2, 2),
                default => 0,
            };
            $issueDate = now()->subDays($i % 60);

            $work = TailorWork::create([
                'work_number' => 'DTW-' . $this->suffix($i) . '-' . $i,
                'tailor_id' => $tailorId,
                'product_id' => $productId,
                'rate_card_id' => $card->id ?? null,
                'issued_qty' => $issuedQty,
                'completed_qty' => $completedQty,
                'rate' => $rate,
                'status' => $status,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->copy()->addDays(7)->toDateString(),
                'notes' => 'Demo tailor work order',
                'created_by' => $userId,
            ]);

            if ($completedQty > 0) {
                $work->completions()->create([
                    'tailor_id' => $tailorId,
                    'qty' => $completedQty,
                    'rate' => $rate,
                    'amount' => round($completedQty * $rate, 2),
                    'completed_at' => $issueDate->copy()->addDays(5)->toDateString(),
                    'received_to_stock' => false,
                    'notes' => 'Demo completion entry',
                    'created_by' => $userId,
                ]);
            }
        });
    }

    /**
     * Embroidery job-work dispatches. Weighted towards open jobs so the
     * "Pending Dispatch" screen (status pending/partial) has a queue, with
     * completed jobs — and their receipt entries, which drive the vendor
     * ledger — for the "Received" screen. Needs embroidery vendors and products.
     */
    private function makeEmbroideryWorks(int $count): int
    {
        $vendorIds = EmbroideryVendor::pluck('id')->all();
        $productIds = Product::pluck('id')->all();
        if (empty($vendorIds) || empty($productIds)) {
            return 0;
        }
        $userId = $this->createdBy;
        $offset = (int) EmbroideryWork::count();

        return $this->makeChunked($count, function (int $k) use ($vendorIds, $productIds, $userId, $offset) {
            $i = $offset + $k;
            $vendorId = $vendorIds[$i % count($vendorIds)];
            $status = ['pending', 'pending', 'partial', 'completed', 'cancelled'][$i % 5];
            $dispatchedQty = ($i % 12 + 2) * 25;
            $receivedQty = match ($status) {
                'completed' => $dispatchedQty,
                'partial' => round($dispatchedQty / 2, 2),
                default => 0,
            };
            $cost = ($i % 10 + 1) * 12;
            $dispatchDate = now()->subDays($i % 60);

            $work = EmbroideryWork::create([
                'work_number' => 'DEW-' . $this->suffix($i) . '-' . $i,
                'embroidery_vendor_id' => $vendorId,
                'product_id' => $productIds[$i % count($productIds)],
                'design_number' => 'DSN-' . str_pad((string) ($i % 9999), 4, '0', STR_PAD_LEFT),
                'stitch_count' => ($i % 20 + 3) * 1000,
                'dispatched_qty' => $dispatchedQty,
                'received_qty' => $receivedQty,
                'cost' => $cost,
                'status' => $status,
                'dispatch_date' => $dispatchDate->toDateString(),
                'due_date' => $dispatchDate->copy()->addDays(10)->toDateString(),
                'notes' => 'Demo embroidery job',
                'created_by' => $userId,
            ]);

            if ($receivedQty > 0) {
                $work->receipts()->create([
                    'embroidery_vendor_id' => $vendorId,
                    'qty' => $receivedQty,
                    'cost' => $cost,
                    'amount' => round($receivedQty * $cost, 2),
                    'received_at' => $dispatchDate->copy()->addDays(8)->toDateString(),
                    'received_to_stock' => false,
                    'notes' => 'Demo receipt entry',
                    'created_by' => $userId,
                ]);
            }
        });
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

    /**
     * One rate card per product, each with a starter set of piece-rate
     * operations. (tenant, product_id) is unique, so products that already have
     * a card are skipped — otherwise a second run collides on the first product.
     */
    private function makeRateCards(int $count): int
    {
        $productIds = Product::query()
            ->whereNotIn('id', RateCard::query()->select('product_id'))
            ->pluck('id')
            ->all();
        if (empty($productIds)) {
            return 0;
        }
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
                'attendance' => $this->attendanceRow($emp, $i),
                'overtime' => [
                    'employee_id' => $emp,
                    'date' => now()->subDays($i)->toDateString(),
                    'hours' => $i % 4 + 1,
                    'rate' => 100,
                    'amount' => ($i % 4 + 1) * 100,
                    'status' => 'pending',
                ],
                'bonus' => $this->bonusRow($emp, $i, $userId),
                'advance' => $this->advanceRow($emp, $i, $userId),
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
                'salary' => $this->salaryRow($emp, $i),
            };
        }, $model);
    }

    /**
     * One attendance day. Rotates the full status vocabulary (not just
     * present/absent) so the register shows half-days, leave and week-offs,
     * with check-in/out only on days actually worked. (employee, date) is
     * unique — subDays($i) keeps each row's date distinct.
     */
    private function attendanceRow(int $emp, int $i): array
    {
        // Weighted towards "present" so the register looks like a real month.
        $status = ['present', 'present', 'present', 'half-day', 'present', 'absent', 'present', 'leave', 'week-off', 'present'][$i % 10];
        $worked = in_array($status, ['present', 'half-day'], true);

        return [
            'employee_id' => $emp,
            'date' => now()->subDays($i)->toDateString(),
            'status' => $status,
            'check_in' => $worked ? sprintf('09:%02d:00', ($i * 7) % 45) : null,
            'check_out' => $worked ? ($status === 'half-day' ? '13:30:00' : sprintf('18:%02d:00', ($i * 11) % 50)) : null,
            'remarks' => match ($status) {
                'half-day' => 'Left after lunch',
                'absent' => 'Unplanned absence',
                'leave' => 'On approved leave',
                default => null,
            },
        ];
    }

    /** Bonus for a payroll month, spread across the pending → paid lifecycle. */
    private function bonusRow(int $emp, int $i, ?int $userId): array
    {
        $status = ['pending', 'approved', 'paid', 'paid', 'rejected'][$i % 5];
        $decided = $status !== 'pending';

        return [
            'employee_id' => $emp,
            'month' => now()->subMonths($i % 18)->format('Y-m-01'),
            'type' => ['performance', 'festival', 'diwali', 'attendance', 'statutory'][$i % 5],
            'amount' => ($i % 20 + 1) * 500,
            'remarks' => $status === 'rejected' ? 'Not eligible for this cycle.' : 'Demo bonus entry',
            'status' => $status,
            'approved_by' => $decided ? $userId : null,
            'approved_at' => $decided ? now()->subDays($i % 30) : null,
        ];
    }

    /** Salary advance with recovery progress matching its status. */
    private function advanceRow(int $emp, int $i, ?int $userId): array
    {
        $status = ['pending', 'approved', 'recovering', 'recovering', 'closed', 'rejected'][$i % 6];
        $amount = ($i % 10 + 1) * 1000;
        $monthly = max(500, round($amount / 5, 2));
        $recovered = match ($status) {
            'closed' => $amount,
            'recovering' => min($amount, $monthly * (($i % 3) + 1)),
            default => 0,
        };
        $decided = ! in_array($status, ['pending'], true);

        return [
            'employee_id' => $emp,
            'date' => now()->subDays($i % 120)->toDateString(),
            'amount' => $amount,
            'monthly_recovery' => $monthly,
            'recovered' => $recovered,
            'reason' => ['Medical emergency', 'Festival expenses', 'Home repair', 'School fees', 'Family travel'][$i % 5],
            'status' => $status,
            'approved_by' => $decided ? $userId : null,
            'approved_at' => $decided ? now()->subDays($i % 60) : null,
        ];
    }

    /**
     * A payroll row whose components add up: gross = basic + allowances,
     * net = gross - deductions, so both the Salary Register columns and the
     * detailed payroll fields are consistent. (employee, month) is unique —
     * subMonths($i) keeps each month distinct.
     */
    private function salaryRow(int $emp, int $i): array
    {
        $basic = 15000 + ($i % 10) * 1500;
        $hra = round($basic * 0.4, 2);
        $conveyance = 1600.0;
        $medical = 1250.0;
        $special = 2000.0;
        $allowances = round($hra + $conveyance + $medical + $special, 2);
        $gross = round($basic + $allowances, 2);

        $pf = round($basic * 0.12, 2);
        $professionalTax = 200.0;
        $advance = $i % 6 === 0 ? 1000.0 : 0.0;
        $loan = $i % 7 === 0 ? 1500.0 : 0.0;
        $deductions = round($pf + $professionalTax + $advance + $loan, 2);

        $month = now()->subMonths($i)->startOfMonth();
        // Anything older than last month is settled; recent months stay draft.
        $paid = $i >= 2;

        return [
            'employee_id' => $emp,
            'month' => $month->format('Y-m-01'),
            'basic' => $basic,
            'hra' => $hra,
            'conveyance' => $conveyance,
            'medical' => $medical,
            'special_allowance' => $special,
            'allowances' => $allowances,
            'gross' => $gross,
            'pf_employee' => $pf,
            'professional_tax' => $professionalTax,
            'advance_deduction' => $advance,
            'loan_deduction' => $loan,
            'deductions' => $deductions,
            'net_salary' => round($gross - $deductions, 2),
            'working_days' => 26,
            'present_days' => 26,
            'paid_days' => 26,
            'lop_days' => 0,
            'status' => $paid ? 'paid' : 'draft',
            'paid_on' => $paid ? $month->copy()->endOfMonth()->addDays(2)->toDateString() : null,
        ];
    }

    /**
     * Employee loans across the pending → disbursed → closed lifecycle. A
     * disbursed or closed loan also gets its monthly repayment schedule, with
     * the instalments already due marked paid — mirroring the amortisation the
     * loan controller applies on disburse.
     */
    private function makeEmployeeLoans(int $count): int
    {
        $employeeIds = Employee::pluck('id')->all();
        if (empty($employeeIds)) {
            return 0;
        }
        $userId = $this->createdBy;
        $n = count($employeeIds);
        $offset = (int) EmployeeLoan::count();

        return $this->makeChunked($count, function (int $k) use ($employeeIds, $n, $userId, $offset) {
            $i = $offset + $k;
            $status = ['pending', 'approved', 'disbursed', 'disbursed', 'closed', 'rejected'][$i % 6];
            $principal = (float) (($i % 20 + 5) * 5000);
            $rate = 10.0;
            $tenure = [6, 12, 18, 24][$i % 4];
            // Flat-rate amortisation, same formula as EmployeeLoanController.
            $interest = round($principal * ($rate / 100) * ($tenure / 12), 2);
            $totalPayable = round($principal + $interest, 2);
            $emi = round($totalPayable / $tenure, 2);
            $disbursedOn = now()->subMonths($i % 18)->startOfMonth();

            // Whole instalments already behind us; a closed loan is fully repaid.
            // (int) matters: diffInMonths() is fractional, and a part-month must
            // not count as a paid EMI or `repaid` drifts from the schedule.
            $elapsed = min($tenure, max(0, (int) $disbursedOn->diffInMonths(now())));
            $paidCount = $status === 'closed' ? $tenure : ($status === 'disbursed' ? $elapsed : 0);

            $loan = EmployeeLoan::create([
                'employee_id' => $employeeIds[$i % $n],
                'loan_number' => 'DLOAN-' . $this->suffix($i) . '-' . $i,
                'principal' => $principal,
                'interest_rate' => $rate,
                'tenure_months' => $tenure,
                'emi' => $emi,
                'total_payable' => $totalPayable,
                'repaid' => $paidCount === $tenure ? $totalPayable : round($emi * $paidCount, 2),
                'disbursed_on' => $disbursedOn->toDateString(),
                'reason' => ['Home renovation', 'Medical treatment', 'Wedding expenses', 'Vehicle purchase', 'Education fees'][$i % 5],
                'status' => $status,
                'approved_by' => in_array($status, ['pending'], true) ? null : $userId,
                'approved_at' => in_array($status, ['pending'], true) ? null : $disbursedOn->copy()->subDays(3),
            ]);

            if (! in_array($status, ['disbursed', 'closed'], true)) {
                return;
            }

            for ($inst = 1; $inst <= $tenure; $inst++) {
                // Last instalment absorbs the rounding, as the controller does.
                $amount = $inst < $tenure ? $emi : round($totalPayable - $emi * ($tenure - 1), 2);
                $dueDate = $disbursedOn->copy()->addMonths($inst)->endOfMonth();
                LoanRepayment::create([
                    'employee_loan_id' => $loan->id,
                    'installment_no' => $inst,
                    'due_date' => $dueDate->toDateString(),
                    'amount' => $amount,
                    'status' => $inst <= $paidCount ? 'paid' : 'pending',
                    'paid_on' => $inst <= $paidCount ? $dueDate->toDateString() : null,
                ]);
            }
        });
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

        $userId = $this->createdBy;

        return $this->make($count, function (int $i) use ($employeeIds, $leaveTypeIds, $userId) {
            $from = now()->subDays($i % 200);
            // Every 5th request is a half-day; the rest run 1–4 days.
            $halfDay = $i % 5 === 4;
            $spanDays = $i % 4;
            $status = ['pending', 'approved', 'approved', 'rejected', 'cancelled'][$i % 5];
            $decided = in_array($status, ['approved', 'rejected'], true);

            return [
                'employee_id' => $employeeIds[$i % count($employeeIds)],
                'leave_type_id' => $leaveTypeIds[$i % count($leaveTypeIds)],
                'from_date' => $from->toDateString(),
                'to_date' => $halfDay ? $from->toDateString() : $from->copy()->addDays($spanDays)->toDateString(),
                'days' => $halfDay ? 0.5 : $spanDays + 1,
                'half_day' => $halfDay,
                'reason' => ['Family function', 'Not well', 'Personal work', 'Out of town', 'Medical check-up'][$i % 5],
                'status' => $status,
                'approved_by' => $decided ? $userId : null,
                'approved_at' => $decided ? $from->copy()->subDay() : null,
                'remarks' => $status === 'rejected' ? 'Peak season — cannot spare.' : null,
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

    /**
     * Dispatch plans: a vehicle + driver + route going out with 1–3 consignments
     * (customer drops). Statuses cycle draft → dispatched → delivered →
     * cancelled, and each plan's consignments stay consistent with its header —
     * a delivered plan's drops all carry proof of delivery, a dispatched one is
     * mid-run (first drop done, the rest pending). Needs customers; vehicle,
     * driver and route are optional. Returns plans created.
     */
    private function makeDispatchPlans(int $count): int
    {
        $customerIds = Customer::pluck('id')->all();
        if (empty($customerIds)) {
            return 0;
        }
        $fleet = $this->dispatchFleet();
        $ordersByCustomer = $this->salesOrdersByCustomer();
        $userId = $this->createdBy;
        $offset = (int) DispatchPlan::count();

        return $this->makeChunked($count, function (int $k) use ($customerIds, $fleet, $ordersByCustomer, $userId, $offset) {
            $i = $offset + $k;
            $status = ['draft', 'dispatched', 'dispatched', 'delivered', 'cancelled'][$i % 5];
            $date = now()->subDays($i % 45);
            $rolledOut = in_array($status, ['dispatched', 'delivered'], true);

            $plan = DispatchPlan::create([
                'dispatch_number' => 'DDSP-' . $this->suffix($i) . '-' . $i,
                'dispatch_date' => $date->toDateString(),
                'vehicle_id' => $fleet['vehicles'] ? $fleet['vehicles'][$i % count($fleet['vehicles'])] : null,
                'driver_id' => $fleet['drivers'] ? $fleet['drivers'][$i % count($fleet['drivers'])] : null,
                'route_id' => $fleet['routes'] ? $fleet['routes'][$i % count($fleet['routes'])] : null,
                'status' => $status,
                'remarks' => 'Demo dispatch plan',
                'dispatched_at' => $rolledOut ? $date->copy()->setTime(8, 30) : null,
                'delivered_at' => $status === 'delivered' ? $date->copy()->setTime(19, 0) : null,
                'created_by' => $userId,
            ]);

            $drops = ($i % 3) + 1;
            for ($d = 0; $d < $drops; $d++) {
                // Delivered plan → every drop done. Dispatched → the first drop
                // is done and the rest await POD. Draft/cancelled → all pending.
                $deliveryStatus = match (true) {
                    $status === 'delivered' => 'delivered',
                    $status === 'dispatched' && $d === 0 => 'delivered',
                    default => 'pending',
                };
                $plan->items()->create($this->dispatchItemRow(
                    $i + $d,
                    $d + 1,
                    $customerIds,
                    $ordersByCustomer,
                    $deliveryStatus,
                    $date->copy()->setTime(11 + $d, 15),
                ));
            }
        });
    }

    /**
     * Proof-of-Delivery rows. A POD *is* a consignment on a dispatched plan, so
     * this creates carrier plans of up to 3 drops whose consignments already
     * carry recorded proof — receiver, timestamp and remarks — plus the odd
     * failed and still-pending drop so the POD screen's filters have data. A
     * plan with nothing left pending is marked delivered, exactly as recording
     * the last POD would. Returns consignments created.
     */
    private function makeDispatchPods(int $count): int
    {
        $customerIds = Customer::pluck('id')->all();
        if (empty($customerIds)) {
            return 0;
        }
        $fleet = $this->dispatchFleet();
        $ordersByCustomer = $this->salesOrdersByCustomer();
        $userId = $this->createdBy;
        $planOffset = (int) DispatchPlan::count();
        $itemOffset = (int) DispatchItem::count();

        $made = 0;
        $plans = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use (
                $batch, $customerIds, $fleet, $ordersByCustomer, $userId, $planOffset, $itemOffset, &$made, &$plans
            ) {
                $done = 0;
                while ($done < $batch) {
                    $drops = min(self::POD_DROPS_PER_PLAN, $batch - $done);
                    $p = $planOffset + $plans;
                    $date = now()->subDays($p % 30);

                    $plan = DispatchPlan::create([
                        'dispatch_number' => 'DPOD-' . $this->suffix($p) . '-' . $p,
                        'dispatch_date' => $date->toDateString(),
                        'vehicle_id' => $fleet['vehicles'] ? $fleet['vehicles'][$p % count($fleet['vehicles'])] : null,
                        'driver_id' => $fleet['drivers'] ? $fleet['drivers'][$p % count($fleet['drivers'])] : null,
                        'route_id' => $fleet['routes'] ? $fleet['routes'][$p % count($fleet['routes'])] : null,
                        'status' => 'dispatched',
                        'remarks' => 'Demo delivery run',
                        'dispatched_at' => $date->copy()->setTime(8, 30),
                        'created_by' => $userId,
                    ]);

                    $pending = false;
                    for ($d = 0; $d < $drops; $d++) {
                        $item = $itemOffset + $made + $d;
                        $deliveryStatus = ['delivered', 'delivered', 'delivered', 'failed', 'pending'][$item % 5];
                        $pending = $pending || $deliveryStatus === 'pending';
                        $plan->items()->create($this->dispatchItemRow(
                            $item,
                            $d + 1,
                            $customerIds,
                            $ordersByCustomer,
                            $deliveryStatus,
                            $date->copy()->setTime(11 + $d, 15),
                        ));
                    }

                    // Mirrors ProofOfDeliveryController::settlePlan().
                    if (! $pending) {
                        $plan->update(['status' => 'delivered', 'delivered_at' => $date->copy()->setTime(19, 0)]);
                    }

                    $done += $drops;
                    $made += $drops;
                    $plans++;
                }
            });
        }

        return $made;
    }

    /** Optional dispatch header references — any of these may be empty. */
    private function dispatchFleet(): array
    {
        return [
            'vehicles' => Vehicle::pluck('id')->all(),
            'drivers' => Driver::pluck('id')->all(),
            'routes' => DeliveryRoute::pluck('id')->all(),
        ];
    }

    /** customer_id => [sales order ids], so a drop can cite that customer's order. */
    private function salesOrdersByCustomer(): array
    {
        return SalesOrder::query()
            ->select('id', 'customer_id')
            ->whereNotNull('customer_id')
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($orders) => $orders->pluck('id')->all())
            ->all();
    }

    /**
     * One consignment (customer drop) on a dispatch plan. The proof-of-delivery
     * fields are filled only for a delivered drop; a failed one keeps the reason
     * in pod_remarks with no receiver, as the POD screen records it.
     */
    private function dispatchItemRow(
        int $i,
        int $sequence,
        array $customerIds,
        array $ordersByCustomer,
        string $deliveryStatus,
        $deliveredAt,
    ): array {
        $customerId = $customerIds[$i % count($customerIds)];
        $orders = $ordersByCustomer[$customerId] ?? [];
        $delivered = $deliveryStatus === 'delivered';

        return [
            'customer_id' => $customerId,
            'sales_order_id' => $orders ? $orders[$i % count($orders)] : null,
            'delivery_address' => ($i % 90 + 1) . ', ' . $this->last[$i % count($this->last)] . ' Road, '
                . $this->cities[$i % count($this->cities)],
            'packages' => $i % 5 + 1,
            'weight_kg' => round(($i % 40 + 1) * 2.5, 2),
            'cod_amount' => $i % 4 === 0 ? ($i % 20 + 1) * 250 : 0,
            'sequence' => $sequence,
            'delivery_status' => $deliveryStatus,
            'delivered_at' => $delivered ? $deliveredAt : null,
            'received_by' => $delivered ? $this->person($i) : null,
            'receiver_phone' => $delivered ? $this->phone($i) : null,
            'pod_remarks' => match ($deliveryStatus) {
                'delivered' => 'Received in good condition.',
                'failed' => 'Customer unavailable at the drop point.',
                default => null,
            },
        ];
    }

    // ---- Import & Export Logistics (§24) --------------------------------------

    /**
     * Countries. `code` is ISO alpha-2 and unique per tenant, so it has no room
     * for the usual time suffix: rows are taken from the real list first and then
     * from generated two-letter codes, and any code already on file is skipped
     * (which also makes a re-run additive instead of a constraint violation).
     */
    private function makeCountries(int $count): int
    {
        $taken = Country::pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();
        $pool = count($this->countryDefs);
        $made = 0;
        $i = 0;

        // Bounded by the 26×26 code space; stop early rather than spin forever.
        while ($made < $count && $i < $pool + 676) {
            $def = $i < $pool
                ? $this->countryDefs[$i]
                : $this->syntheticCountry($i - $pool);
            $i++;

            if (in_array($def[0], $taken, true)) {
                continue;
            }
            $taken[] = $def[0];

            Country::create([
                'code' => $def[0],
                'iso3' => $def[1],
                'name' => $def[2],
                'currency_code' => $def[3],
                'dial_code' => $def[4],
                'region' => $def[5],
                'is_active' => true,
            ]);
            $made++;
        }

        return $made;
    }

    /**
     * A filler country once the real list runs out: "AA", "AB" … kept inside the
     * two-letter code space the column allows.
     *
     * @return array{0:string,1:string,2:string,3:string,4:string,5:string}
     */
    private function syntheticCountry(int $n): array
    {
        $code = chr(65 + intdiv($n, 26) % 26) . chr(65 + $n % 26);

        return [$code, $code . 'X', 'Demo Country ' . $code, 'USD', '+' . (900 + $n % 99), 'Other'];
    }

    /** Ports hang off the country catalogue; the FK is left null when it is empty. */
    private function makePorts(int $count): int
    {
        $countryIds = Country::pluck('id')->all();

        return $this->make($count, function (int $i) use ($countryIds) {
            $def = $this->portDefs[$i % count($this->portDefs)];
            $type = ['sea', 'sea', 'air', 'icd', 'cfs'][$i % 5];

            return [
                'code' => 'D' . strtoupper(substr($def[1], 0, 3)) . $this->suffix($i) . $i,
                'name' => $def[0] . ' ' . ucfirst($type === 'sea' ? 'port' : $type) . ' ' . ($i + 1),
                'type' => $type,
                'country_id' => $countryIds ? $countryIds[$i % count($countryIds)] : null,
                'city' => $def[0],
                'state' => $def[2],
                'is_active' => true,
            ];
        }, Port::class);
    }

    private function shippingLineRow(int $i): array
    {
        $name = $this->carrierNames[$i % count($this->carrierNames)];

        return [
            'code' => 'DSL-' . $this->suffix($i) . '-' . $i,
            'name' => $name . ' Lines ' . ($i + 1),
            'scac_code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 4)),
            'contact_person' => $this->person($i),
            'phone' => $this->phone($i),
            'email' => 'line' . $i . '.' . strtolower($this->suffix($i)) . '@example.com',
            'website' => 'https://example.com/' . Str::slug($name),
            'address' => ($i + 1) . ', Harbour Road, ' . $this->cities[$i % count($this->cities)],
            'is_active' => true,
        ];
    }

    private function airlineRow(int $i): array
    {
        $name = $this->airlineNames[$i % count($this->airlineNames)];

        return [
            // The IATA column is 10 chars, so this one carries no trailing index.
            'code' => 'DA' . $this->suffix($i),
            'icao_code' => 'D' . strtoupper(substr($this->suffix($i), 0, 3)),
            'name' => $name . ' Cargo ' . ($i + 1),
            'awb_prefix' => (string) (100 + $i % 899),
            'contact_person' => $this->person($i),
            'phone' => $this->phone($i),
            'email' => 'airline' . $i . '.' . strtolower($this->suffix($i)) . '@example.com',
            'website' => 'https://example.com/' . Str::slug($name),
            'address' => 'Cargo Terminal ' . (($i % 4) + 1) . ', ' . $this->cities[$i % count($this->cities)] . ' Airport',
            'is_active' => true,
        ];
    }

    private function makeFreightForwarders(int $count): int
    {
        $countryIds = Country::pluck('id')->all();

        return $this->make($count, function (int $i) use ($countryIds) {
            $city = $this->cities[$i % count($this->cities)];

            return [
                'code' => 'DFF-' . $this->suffix($i) . '-' . $i,
                'name' => $this->company($i) . ' Logistics',
                'contact_person' => $this->person($i),
                'phone' => $this->phone($i),
                'email' => 'forwarder' . $i . '.' . strtolower($this->suffix($i)) . '@example.com',
                'gst_number' => $this->gstin($i),
                'pan_number' => $this->pan($i),
                'country_id' => $countryIds ? $countryIds[$i % count($countryIds)] : null,
                'address' => ($i + 1) . ', Freight Plaza, ' . $city,
                'city' => $city,
                'is_active' => true,
            ];
        }, FreightForwarder::class);
    }

    /** CHA agents are licensed at a home port; the FK is null with no ports yet. */
    private function makeChaAgents(int $count): int
    {
        $portIds = Port::pluck('id')->all();

        return $this->make($count, function (int $i) use ($portIds) {
            return [
                'code' => 'DCHA-' . $this->suffix($i) . '-' . $i,
                'name' => $this->company($i) . ' Clearing Agency',
                'license_no' => 'CHA/' . (2020 + $i % 6) . '/' . str_pad((string) ($i % 9999), 4, '0', STR_PAD_LEFT),
                'license_expiry' => now()->addDays(120 + $i % 900)->toDateString(),
                'port_id' => $portIds ? $portIds[$i % count($portIds)] : null,
                'contact_person' => $this->person($i),
                'phone' => $this->phone($i),
                'email' => 'cha' . $i . '.' . strtolower($this->suffix($i)) . '@example.com',
                'gst_number' => $this->gstin($i),
                'address' => ($i + 1) . ', Customs House Lane, ' . $this->cities[$i % count($this->cities)],
                'is_active' => true,
            ];
        }, ChaAgent::class);
    }

    private function transporterRow(int $i): array
    {
        $city = $this->cities[$i % count($this->cities)];

        return [
            'code' => 'DTRP-' . $this->suffix($i) . '-' . $i,
            'name' => $this->company($i) . ' Roadways',
            'contact_person' => $this->person($i),
            'phone' => $this->phone($i),
            'email' => 'transporter' . $i . '.' . strtolower($this->suffix($i)) . '@example.com',
            'gst_number' => $this->gstin($i),
            'pan_number' => $this->pan($i),
            'transport_mode' => ['road', 'road', 'rail', 'multimodal'][$i % 4],
            'vehicle_types' => ['20ft trailer, 40ft trailer', 'Container truck, tempo', 'Flatbed, low-bed', 'Rake, trailer'][$i % 4],
            'address' => ($i + 1) . ', Transport Nagar, ' . $city,
            'city' => $city,
            'is_active' => true,
        ];
    }

    /**
     * Overseas suppliers / customers. These are not their own tables — the
     * ordinary party masters carry `is_overseas` plus the trade attributes, so a
     * PO / SO on them can become an import / export shipment (see the module's
     * migration). Countries and ports are used when they exist.
     */
    private function makeOverseasParties(int $count, string $kind): int
    {
        $countries = Country::select('id', 'currency_code')->get();
        $portIds = Port::pluck('id')->all();
        $isSupplier = $kind === 'supplier';

        return $this->make($count, function (int $i) use ($countries, $portIds, $isSupplier) {
            $country = $countries->isNotEmpty() ? $countries[$i % $countries->count()] : null;
            $currency = $country?->currency_code ?: $this->currencies[$i % count($this->currencies)];
            $base = $isSupplier ? $this->supplierRow($i) : $this->customerRow($i);

            $overseas = [
                'code' => ($isSupplier ? 'DOS-' : 'DOC-') . $this->suffix($i) . '-' . $i,
                'company_name' => $this->company($i) . ' ' . ['International', 'Global', 'Trading FZE', 'Overseas Ltd'][$i % 4],
                'city' => $this->foreignCities[$i % count($this->foreignCities)],
                'state' => null,
                'country' => $country ? null : 'Overseas',
                'is_overseas' => true,
                'country_id' => $country?->id,
                'currency_code' => $currency,
                'incoterm' => ['FOB', 'CIF', 'CFR', 'EXW', 'DAP'][$i % 5],
                'port_id' => $portIds ? $portIds[$i % count($portIds)] : null,
            ];

            $overseas += $isSupplier
                ? [
                    'swift_code' => strtoupper(substr($this->suffix($i), 0, 4)) . 'US33',
                    'bank_details' => 'Demo Overseas Bank, A/C ' . (70000000 + $i),
                ]
                : [
                    'lc_terms' => ['LC at sight', 'LC 30 days', 'LC 60 days', 'Advance TT'][$i % 4],
                    'iec_code' => 'IEC' . str_pad((string) ($i % 9999999), 7, '0', STR_PAD_LEFT),
                ];

            // The country column on the base row is a free-text name; keep it in
            // step with the linked country so both screens read the same.
            if ($country) {
                $overseas['country'] = Country::find($country->id)?->name ?? 'Overseas';
            }

            return $overseas + $base;
        }, $isSupplier ? Supplier::class : Customer::class);
    }

    /**
     * Import / export consignments with the whole trail behind them: containers,
     * the trade document set, timeline milestones, logistics cost heads and the
     * settlements of the goods value. Every board in the module is a projection
     * of this table plus those children, so one run populates all of them —
     * Active Imports/Exports, ETA/ETD, Pending Documents / Customs / Payments,
     * Container Status, Shipment Profit and Logistics Expenses.
     *
     * Every master reference is nullable, so this runs with nothing else on file;
     * generating the exim masters (and orders) first simply gives it real lanes,
     * carriers and agents to point at. Returns shipments created.
     */
    private function makeShipments(int $count, string $direction): int
    {
        $isImport = $direction === 'import';
        $refs = $this->shipmentRefs($isImport);
        $userId = $this->createdBy;
        $offset = (int) Shipment::where('direction', $direction)->count();
        // Statuses weighted towards the working states so the active boards fill
        // up, with the terminal ones present for history.
        $statuses = [
            'booked', 'in_transit', 'in_transit', 'arrived', 'customs',
            'cleared', 'delivered', 'closed', 'draft', 'cancelled',
        ];

        return $this->makeChunked($count, function (int $k) use ($isImport, $direction, $refs, $userId, $offset, $statuses) {
            $i = $offset + $k;
            $status = $statuses[$i % count($statuses)];
            $mode = ['sea', 'sea', 'sea', 'air', 'road'][$i % 5];

            $shipment = Shipment::create(
                $this->shipmentHeader($i, $direction, $isImport, $status, $mode, $refs, $userId),
            );

            if ($mode !== 'air') {
                $this->makeShipmentContainers($shipment, $i, $status);
            }
            $this->makeShipmentDocuments($shipment, $i, $direction, $status, $userId);
            $this->makeShipmentEvents($shipment, $i, $status, $userId);
            $this->makeShipmentExpenses($shipment, $i, $mode, $refs, $userId);
            $this->makeShipmentPayments($shipment, $i, $isImport, $refs, $userId);

            if (! $isImport) {
                $this->makeShipmentPacking($shipment, $i, $refs);
                $shipment->syncCargoTotals();
            }
        });
    }

    /** The masters that can bill a logistics cost or bank a settlement. */
    private function shipmentVendorPools(): array
    {
        return [
            'shipping_lines' => ShippingLine::pluck('id')->all(),
            'airlines' => Airline::pluck('id')->all(),
            'forwarders' => FreightForwarder::pluck('id')->all(),
            'cha_agents' => ChaAgent::pluck('id')->all(),
            'transporters' => Transporter::pluck('id')->all(),
            'bank_accounts' => BankAccount::pluck('id')->all(),
        ];
    }

    /** Master ids a shipment can point at — every one of them optional. */
    private function shipmentRefs(bool $isImport): array
    {
        return $this->shipmentVendorPools() + [
            'suppliers' => $isImport
                ? Supplier::orderByDesc('is_overseas')->pluck('id')->all()
                : [],
            'customers' => $isImport
                ? []
                : Customer::orderByDesc('is_overseas')->pluck('id')->all(),
            'purchase_orders' => $isImport ? PurchaseOrder::pluck('id')->all() : [],
            'sales_orders' => $isImport ? [] : SalesOrder::pluck('id')->all(),
            'warehouses' => Warehouse::pluck('id')->all(),
            'countries' => Country::pluck('id')->all(),
            'ports' => Port::pluck('id')->all(),
            'products' => Product::select('id', 'name')->get(),
        ];
    }

    /** Pick from an optional id pool, or null when it is empty. */
    private function pick(array $pool, int $i): ?int
    {
        return $pool ? $pool[$i % count($pool)] : null;
    }

    /**
     * The shipment header: trade block, schedule, cargo, money, customs track and
     * payment track, all consistent with the status the row is in.
     */
    private function shipmentHeader(
        int $i,
        string $direction,
        bool $isImport,
        string $status,
        string $mode,
        array $refs,
        ?int $userId,
    ): array {
        $date = now()->subDays($i % 150);
        $etd = $date->copy()->addDays(3 + $i % 5);
        $eta = $etd->copy()->addDays($mode === 'air' ? 3 : 18 + $i % 12);
        // Departed once booked is behind us; arrived from 'arrived' onwards.
        $departed = ! in_array($status, ['draft', 'booked', 'cancelled'], true);
        $arrived = in_array($status, ['arrived', 'customs', 'cleared', 'delivered', 'closed'], true);

        // Nothing has happened yet on a draft, and a cancelled consignment never
        // filed or settled — so both keep their customs and money tracks clean.
        $live = ! in_array($status, ['draft', 'cancelled'], true);
        $currency = $this->currencies[$i % count($this->currencies)];
        $rate = round($this->fxRates[$currency] + ($i % 20) * 0.05, 6);
        $value = ($i % 40 + 5) * 1250;
        $freight = ($i % 12 + 1) * 150;
        $insurance = round($value * 0.005, 2);
        $duty = $this->shipmentDuty($i, $isImport, $value * $rate, $live);
        $settled = $live ? match ($i % 3) {
            0 => round($value * $rate, 2),
            1 => round($value * $rate * 0.4, 2),
            default => 0.0,
        } : 0.0;
        $lc = $i % 4 === 0;

        return [
            'shipment_number' => ($isImport ? 'DIMP-' : 'DEXP-') . $this->suffix($i) . '-' . $i,
            'direction' => $direction,
            'status' => $status,
            'shipment_date' => $date->toDateString(),
            'supplier_id' => $this->pick($refs['suppliers'], $i),
            'customer_id' => $this->pick($refs['customers'], $i),
            // Half the rows cite the order they came from; the rest were raised
            // before one existed, which the boards have to cope with too.
            'purchase_order_id' => $i % 2 === 0 ? $this->pick($refs['purchase_orders'], $i) : null,
            'sales_order_id' => $i % 2 === 0 ? $this->pick($refs['sales_orders'], $i) : null,
            'warehouse_id' => $this->pick($refs['warehouses'], $i),
            'mode' => $mode,
            'incoterm' => ['FOB', 'CIF', 'CFR', 'EXW', 'DAP', 'DDP'][$i % 6],
            'shipping_line_id' => $mode === 'sea' ? $this->pick($refs['shipping_lines'], $i) : null,
            'airline_id' => $mode === 'air' ? $this->pick($refs['airlines'], $i) : null,
            'freight_forwarder_id' => $this->pick($refs['forwarders'], $i),
            'cha_agent_id' => $this->pick($refs['cha_agents'], $i),
            'transporter_id' => $this->pick($refs['transporters'], $i),
            'bl_awb_number' => ($mode === 'air' ? 'DAWB' : 'DBL') . $this->suffix($i) . $i,
            'bl_awb_date' => $etd->copy()->subDays(2)->toDateString(),
            'vessel_name' => $mode === 'sea' ? 'MV ' . $this->vessels[$i % count($this->vessels)] : null,
            'voyage_number' => $mode === 'sea' ? 'V' . (100 + $i % 899) . 'E' : null,
            // Export bookings carry the cut-offs the desk works backwards from.
            'booking_number' => $isImport ? null : 'DBK' . $this->suffix($i) . $i,
            'booking_date' => $isImport ? null : $date->copy()->addDay()->toDateString(),
            'si_cutoff' => $isImport ? null : $etd->copy()->subDays(3)->toDateString(),
            'cargo_cutoff' => $isImport ? null : $etd->copy()->subDays(2)->toDateString(),
            'origin_country_id' => $this->pick($refs['countries'], $isImport ? $i : $i + 1),
            'destination_country_id' => $this->pick($refs['countries'], $isImport ? $i + 1 : $i),
            'port_of_loading_id' => $this->pick($refs['ports'], $i),
            'port_of_discharge_id' => $this->pick($refs['ports'], $i + 1),
            'final_place' => $this->cities[$i % count($this->cities)] . ' Factory',
            'etd' => $etd->toDateString(),
            'eta' => $eta->toDateString(),
            'atd' => $departed ? $etd->copy()->addDays($i % 3)->toDateString() : null,
            'ata' => $arrived ? $eta->copy()->addDays($i % 4)->toDateString() : null,
            'goods_description' => $this->productNouns[$i % count($this->productNouns)] . ' — ' . (($i % 8) + 1) . ' assorted lots',
            'packages' => ($i % 40) + 10,
            'gross_weight_kg' => round((($i % 40) + 10) * 26.5, 3),
            'net_weight_kg' => round((($i % 40) + 10) * 24.2, 3),
            'cbm' => round((($i % 40) + 10) * 0.14, 3),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'invoice_number' => ($isImport ? 'DSINV-' : 'DEINV-') . $this->suffix($i) . $i,
            'invoice_date' => $date->toDateString(),
            'invoice_value' => $value,
            'freight_amount' => $freight,
            'insurance_amount' => $insurance,
            'goods_cost' => round($value * $rate * ($isImport ? 1 : 0.72), 2),
            'remarks' => 'Demo ' . $direction . ' shipment',
            'created_by' => $userId,
            // Settlement track — Pending Payments reads these.
            'payment_status' => $settled <= 0 ? 'unpaid' : ($settled >= round($value * $rate, 2) ? 'paid' : 'partial'),
            'amount_settled' => $settled,
            'payment_due_date' => $date->copy()->addDays(45)->toDateString(),
            'lc_number' => $lc ? 'LC' . $this->suffix($i) . $i : null,
            'lc_expiry' => $lc ? $date->copy()->addDays(90)->toDateString() : null,
        ] + $duty;
    }

    /**
     * The customs track. An import files a Bill of Entry and pays duty; an export
     * files a Shipping Bill and claims drawback / RoDTEP against its FOB value.
     * Nothing is assessed until the entry is filed, and a shipment that never
     * moved ($live false) has not filed at all.
     *
     * @return array<string, mixed>
     */
    private function shipmentDuty(int $i, bool $isImport, float $valueBase, bool $live): array
    {
        $customsStatus = $live
            ? ['pending', 'filed', 'assessed', 'duty_paid', 'cleared', 'on_hold'][$i % 6]
            : 'pending';
        $filed = $customsStatus !== 'pending';
        $paid = in_array($customsStatus, ['duty_paid', 'cleared'], true);
        $assessable = $filed ? round($valueBase * 1.02, 2) : 0.0;

        $bcd = $isImport ? round($assessable * 0.10, 2) : 0.0;
        $igst = $isImport ? round(($assessable + $bcd) * 0.18, 2) : 0.0;
        $cess = $isImport ? round($bcd * 0.10, 2) : 0.0;

        return [
            'customs_status' => $customsStatus,
            'customs_entry_type' => $isImport ? 'bill_of_entry' : 'shipping_bill',
            'customs_entry_number' => $filed ? ($isImport ? 'BE' : 'SB') . (7000000 + $i) : null,
            'customs_entry_date' => $filed ? now()->subDays($i % 90)->toDateString() : null,
            'icegate_reference' => $filed ? 'ICE' . $this->suffix($i) . $i : null,
            'assessable_value' => $assessable,
            'duty_bcd' => $bcd,
            'duty_igst' => $igst,
            'duty_cess' => $cess,
            'duty_other' => 0,
            'duty_total' => round($bcd + $igst + $cess, 2),
            'duty_paid_at' => $paid ? now()->subDays($i % 60) : null,
            'customs_cleared_at' => $customsStatus === 'cleared' ? now()->subDays($i % 45) : null,
            // Export incentives sit against the shipping bill's FOB value.
            'fob_value' => $isImport || ! $filed ? 0 : round($valueBase, 2),
            'drawback_amount' => $isImport || ! $filed ? 0 : round($valueBase * 0.012, 2),
            'rodtep_amount' => $isImport || ! $filed ? 0 : round($valueBase * 0.008, 2),
            'leo_date' => ! $isImport && $paid ? now()->subDays($i % 40)->toDateString() : null,
        ];
    }

    /**
     * Top up existing shipments with one more child row each — the modules behind
     * the module's remaining boards (Container Status, Pending Documents,
     * Shipment Timeline, Pending Payments, Logistics Expenses). Each board is a
     * projection of one of these child tables, so these deepen a board without
     * raising more consignments; the shipment modules are what create those.
     *
     * Returns 0 with no shipments on file — generate Import / Export Shipments
     * first, exactly as branches need companies.
     */
    private function makeShipmentChildren(int $count, string $kind): int
    {
        $shipments = Shipment::query()
            ->select([
                'id', 'direction', 'status', 'mode', 'currency', 'exchange_rate',
                'freight_amount', 'insurance_amount', 'invoice_value', 'lc_number',
            ])
            ->get();
        if ($shipments->isEmpty()) {
            return 0;
        }

        $refs = $this->shipmentVendorPools();
        $userId = $this->createdBy;
        $model = match ($kind) {
            'container' => ShipmentContainer::class,
            'document' => ShipmentDocument::class,
            'event' => ShipmentEvent::class,
            'expense' => ShipmentExpense::class,
            'payment' => ShipmentPayment::class,
        };
        $offset = (int) $model::count();
        $total = $shipments->count();

        return $this->makeChunked($count, function (int $k) use ($kind, $shipments, $total, $refs, $userId, $offset) {
            $i = $offset + $k;
            $shipment = $shipments[$i % $total];

            match ($kind) {
                'container' => $shipment->containers()->create(
                    $this->shipmentContainerRow($i, $shipment->status),
                ),
                'document' => $shipment->documents()->create(
                    $this->shipmentDocumentRow($i, $this->rotatingDocType($shipment->direction, $i), $shipment->status, $userId),
                ),
                'event' => $shipment->events()->create(
                    $this->shipmentEventRow($i, $this->milestoneKeys[$i % count($this->milestoneKeys)], now()->subDays($i % 60), $userId),
                ),
                'expense' => $shipment->expenses()->create($this->shipmentExpenseRow(
                    $i,
                    $this->shipmentExpenseHeads($shipment, $i, $refs)[$i % 5],
                    $shipment->containers()->value('id'),
                    $userId,
                )),
                'payment' => $this->addShipmentPayment($shipment, $i, $refs, $userId),
            };
        });
    }

    /** A document type for the leg, rotated so a run covers the whole checklist. */
    private function rotatingDocType(string $direction, int $i): string
    {
        $keys = TradeCatalogue::documentKeysFor($direction);

        return $keys[$i % count($keys)];
    }

    /**
     * One settlement on top of what the shipment already has, then re-derive the
     * header's payment track from the ledger so the Pending Payments board never
     * drifts (the same call the controller makes after a payment write).
     */
    private function addShipmentPayment(Shipment $shipment, int $i, array $refs, ?int $userId): void
    {
        $valueBase = round((float) $shipment->invoice_value * (float) $shipment->exchange_rate, 2);
        $outstanding = max(0, $valueBase - (float) $shipment->payments()->sum('base_amount'));
        // Nothing left to settle → book a small part-payment anyway rather than a
        // zero row, so the board has a movement to show.
        $base = $outstanding > 0 ? round($outstanding * [1.0, 0.5, 0.25][$i % 3], 2) : round($valueBase * 0.1, 2);

        $shipment->payments()->create(
            $this->shipmentPaymentRow($shipment, $i, $base, $shipment->direction === 'import', $refs, $userId),
        );
        $shipment->syncPaymentStatus();
    }

    /**
     * 1–3 containers per sea shipment, their status tracking the consignment's,
     * plus the odd damaged box and an expired free-days window so the Container
     * Status board has detention cases to flag.
     */
    private function makeShipmentContainers(Shipment $shipment, int $i, string $status): void
    {
        $boxes = ($i % 3) + 1;

        for ($b = 0; $b < $boxes; $b++) {
            $shipment->containers()->create($this->shipmentContainerRow($i + $b, $status));
        }
    }

    /** One container box, its state following the shipment's. */
    private function shipmentContainerRow(int $n, string $shipmentStatus): array
    {
        $containerStatus = match ($shipmentStatus) {
            'draft', 'cancelled' => 'planned',
            'booked' => 'stuffed',
            'in_transit' => 'loaded',
            'arrived', 'customs' => 'discharged',
            'cleared' => 'gated_out',
            default => ['delivered', 'empty_returned'][$n % 2],
        };
        $freeDays = [7, 10, 14][$n % 3];

        return [
            'container_number' => 'DEMU' . str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
            'container_type' => ['20ft', '40ft', '40hc', 'lcl'][$n % 4],
            'seal_number' => 'SL' . str_pad((string) ($n % 999999), 6, '0', STR_PAD_LEFT),
            'status' => $containerStatus,
            'current_location' => $this->cities[$n % count($this->cities)] . ' Terminal',
            'status_updated_at' => now()->subDays($n % 20),
            'packages' => ($n % 30) + 5,
            'gross_weight_kg' => round((($n % 30) + 5) * 25.5, 3),
            'net_weight_kg' => round((($n % 30) + 5) * 23.1, 3),
            'cbm' => round((($n % 30) + 5) * 0.13, 3),
            'free_days' => $freeDays,
            // Every fourth box is past its free days — a detention case.
            'detention_free_until' => now()->addDays($n % 4 === 0 ? -($n % 6) - 1 : $freeDays)->toDateString(),
            'empty_returned_on' => $containerStatus === 'empty_returned' ? now()->subDays($n % 10)->toDateString() : null,
            'is_damaged' => $n % 9 === 0,
            'damage_remarks' => $n % 9 === 0 ? 'Dent on the left panel, survey report filed.' : null,
            'remarks' => 'Demo container',
        ];
    }

    /**
     * The trade document set for the leg: the mandatory checklist plus a couple of
     * optional ones, with pending / overdue rows left behind so the Pending
     * Documents board is not empty.
     */
    private function makeShipmentDocuments(Shipment $shipment, int $i, string $direction, string $status, ?int $userId): void
    {
        $mandatory = TradeCatalogue::mandatoryDocumentsFor($direction);
        $optional = array_values(array_diff(TradeCatalogue::documentKeysFor($direction), $mandatory));
        $extras = $optional ? array_slice($optional, $i % max(1, count($optional) - 1), 2) : [];

        foreach (array_merge($mandatory, $extras) as $n => $docType) {
            $shipment->documents()->create($this->shipmentDocumentRow(
                $i + $n,
                $docType,
                $status,
                $userId,
                in_array($docType, $mandatory, true),
            ));
        }
    }

    /**
     * One trade document. A settled shipment has its paperwork in; anything still
     * moving keeps some of it pending, and one row in five is rejected.
     */
    private function shipmentDocumentRow(int $n, string $docType, string $shipmentStatus, ?int $userId, ?bool $mandatory = null): array
    {
        $settled = in_array($shipmentStatus, ['cleared', 'delivered', 'closed'], true);
        $status = match (true) {
            $settled => 'verified',
            $n % 5 === 0 => 'rejected',
            $n % 3 === 0 => 'pending',
            default => ['received', 'submitted'][$n % 2],
        };
        $received = ! in_array($status, ['pending', 'rejected'], true);

        return [
            'doc_type' => $docType,
            'doc_number' => $received ? 'DOC' . $this->suffix($n) . $n : null,
            'doc_date' => $received ? now()->subDays($n % 40)->toDateString() : null,
            'due_date' => now()->addDays(($n % 20) - 6)->toDateString(),
            'status' => $status,
            'is_mandatory' => $mandatory ?? (TradeCatalogue::DOCUMENTS[$docType]['mandatory'] ?? false),
            'remarks' => $status === 'rejected' ? 'Returned by the bank — details mismatch.' : null,
            'uploaded_by' => $received ? $userId : null,
        ];
    }

    /** Milestone log behind the timeline, up to where the status has reached. */
    private function makeShipmentEvents(Shipment $shipment, int $i, string $status, ?int $userId): void
    {
        $trail = [
            'booking_confirmed', 'cargo_ready', 'stuffing_done', 'gated_in', 'departed',
            'in_transit', 'arrived', 'discharged', 'customs_cleared', 'delivered',
        ];
        $reached = match ($status) {
            'draft' => 0,
            'cancelled' => 1,
            'booked' => 2,
            'in_transit' => 6,
            'arrived' => 7,
            'customs' => 8,
            'cleared' => 9,
            default => 10,
        };
        $at = now()->subDays(($i % 60) + 30);

        foreach (array_slice($trail, 0, $reached) as $n => $eventType) {
            $shipment->events()->create(
                $this->shipmentEventRow($i + $n, $eventType, $at->copy()->addDays($n * 2), $userId),
            );
        }
    }

    private function shipmentEventRow(int $n, string $eventType, $at, ?int $userId): array
    {
        return [
            'event_type' => $eventType,
            'event_at' => $at,
            'location' => $this->cities[$n % count($this->cities)],
            'description' => TradeCatalogue::MILESTONES[$eventType] ?? 'Milestone',
            'source' => $n % 3 === 0 ? 'manual' : 'system',
            'created_by' => $userId,
        ];
    }

    /**
     * Logistics cost heads — freight and insurance mirror the header amounts (the
     * profit board counts them once), plus the local charges, CHA fee and the odd
     * detention bill, some still unpaid for the expense board's filters.
     */
    private function makeShipmentExpenses(Shipment $shipment, int $i, string $mode, array $refs, ?int $userId): void
    {
        $heads = $this->shipmentExpenseHeads($shipment, $i, $refs);
        if ($i % 4 === 0) {
            $heads[] = ['detention', ($i % 5 + 1) * 4000.0, 'INR', 1.0, $mode === 'air' ? 'airline' : 'shipping_line', $mode === 'air' ? $refs['airlines'] : $refs['shipping_lines']];
        }

        // Detention is billed per box, so it is attributed to one — every other
        // head belongs to the consignment as a whole.
        $containerId = $shipment->containers()->value('id');

        foreach ($heads as $n => $head) {
            $shipment->expenses()->create($this->shipmentExpenseRow($i + $n, $head, $containerId, $userId));
        }
    }

    /**
     * The cost heads a consignment carries: [type, amount, currency, rate,
     * vendor type, vendor id pool].
     *
     * @return list<array{0:string,1:float,2:string,3:float,4:string,5:array}>
     */
    private function shipmentExpenseHeads(Shipment $shipment, int $i, array $refs): array
    {
        $rate = (float) $shipment->exchange_rate;
        $air = $shipment->mode === 'air';
        $carriers = $air ? $refs['airlines'] : $refs['shipping_lines'];

        return [
            [$air ? 'air_freight' : 'ocean_freight', (float) $shipment->freight_amount, $shipment->currency, $rate, 'freight_forwarder', $refs['forwarders']],
            ['insurance', (float) $shipment->insurance_amount, $shipment->currency, $rate, 'other', []],
            ['thc', ($i % 8 + 2) * 1500.0, 'INR', 1.0, $air ? 'airline' : 'shipping_line', $carriers],
            ['cha_charges', ($i % 6 + 2) * 2500.0, 'INR', 1.0, 'cha_agent', $refs['cha_agents']],
            ['inland_transport', ($i % 10 + 3) * 1800.0, 'INR', 1.0, 'transporter', $refs['transporters']],
        ];
    }

    /**
     * One logistics cost line.
     *
     * @param  array{0:string,1:float,2:string,3:float,4:string,5:array}  $head
     */
    private function shipmentExpenseRow(int $n, array $head, ?int $containerId, ?int $userId): array
    {
        [$type, $amount, $currency, $exRate, $vendorType, $pool] = $head;
        $paymentStatus = ['paid', 'unpaid', 'partial', 'paid'][$n % 4];

        return [
            'shipment_container_id' => $type === 'detention' ? $containerId : null,
            'expense_type' => $type,
            'expense_date' => now()->subDays($n % 50)->toDateString(),
            'vendor_type' => $vendorType,
            'vendor_id' => $this->pick($pool, $n),
            'vendor_name' => $this->company($n) . ' Logistics',
            'invoice_number' => 'DEXP' . $this->suffix($n) . $n,
            'currency' => $currency,
            'exchange_rate' => $exRate,
            'amount' => round($amount, 2),
            'base_amount' => round($amount * $exRate, 2),
            'payment_status' => $paymentStatus,
            'paid_amount' => match ($paymentStatus) {
                'paid' => round($amount * $exRate, 2),
                'partial' => round($amount * $exRate * 0.5, 2),
                default => 0,
            },
            'due_date' => now()->addDays(($n % 30) - 10)->toDateString(),
            // Freight collect / DDP terms make a cost recoverable, so the profit
            // board has to leave it out of the cost side.
            'is_billable' => $type !== 'detention' && $n % 5 === 0,
            'remarks' => 'Demo logistics cost',
            'created_by' => $userId,
        ];
    }

    /**
     * Settlements of the goods value, matching the header's amount_settled: the
     * export proceeds come in, the import remittance goes out.
     */
    private function makeShipmentPayments(Shipment $shipment, int $i, bool $isImport, array $refs, ?int $userId): void
    {
        $settled = (float) $shipment->amount_settled;
        if ($settled <= 0) {
            return;
        }

        // A paid shipment settles in two tranches (advance + balance); a partial
        // one has just the first in.
        $tranches = $shipment->payment_status === 'paid' ? [0.3, 0.7] : [1.0];
        foreach ($tranches as $n => $share) {
            $shipment->payments()->create($this->shipmentPaymentRow(
                $shipment,
                $i + $n,
                round($settled * $share, 2),
                $isImport,
                $refs,
                $userId,
            ));
        }
    }

    /** One settlement of the goods value, in the shipment's currency and in base. */
    private function shipmentPaymentRow(Shipment $shipment, int $n, float $base, bool $isImport, array $refs, ?int $userId): array
    {
        $rate = (float) $shipment->exchange_rate;

        return [
            'direction' => $isImport ? 'outbound' : 'inbound',
            'payment_date' => now()->subDays($n % 40)->toDateString(),
            'currency' => $shipment->currency,
            'exchange_rate' => $rate,
            'amount' => $rate > 0 ? round($base / $rate, 2) : $base,
            'base_amount' => $base,
            'mode' => $shipment->lc_number ? 'lc' : ['bank_transfer', 'bank_transfer', 'cheque', 'other'][$n % 4],
            'bank_account_id' => $this->pick($refs['bank_accounts'], $n),
            'reference' => 'SWIFT' . $this->suffix($n) . $n,
            'remarks' => 'Demo settlement',
            'created_by' => $userId,
        ];
    }

    /** Packing list for an export — the lines customs and the buyer read. */
    private function makeShipmentPacking(Shipment $shipment, int $i, array $refs): void
    {
        $products = $refs['products'];
        $lines = ($i % 3) + 1;
        $containerIds = $shipment->containers()->pluck('id')->all();

        for ($n = 0; $n < $lines; $n++) {
            $product = $products->isNotEmpty() ? $products[($i + $n) % $products->count()] : null;
            $packages = (($i + $n) % 20) + 5;
            $length = 60 + ($n * 10);

            $shipment->packingItems()->create([
                'shipment_container_id' => $this->pick($containerIds, $i + $n),
                'product_id' => $product?->id,
                'description' => $product->name ?? $this->productNouns[($i + $n) % count($this->productNouns)],
                'marks_numbers' => 'DEMO/' . ($i + 1) . '/' . ($n + 1),
                'package_type' => ['carton', 'bale', 'pallet', 'roll'][($i + $n) % 4],
                'package_count' => $packages,
                'quantity' => $packages * 12,
                'uom' => 'pcs',
                'net_weight_kg' => round($packages * 18.4, 3),
                'gross_weight_kg' => round($packages * 20.1, 3),
                'length_cm' => $length,
                'width_cm' => 40,
                'height_cm' => 35,
                'cbm' => round($length * 40 * 35 / 1000000 * $packages, 3),
                'hs_code' => (string) (52081100 + (($i + $n) % 99)),
                'remarks' => 'Demo packing line',
                'sort_order' => $n,
            ]);
        }
    }

    /** Plausible GSTIN / PAN for the exim service providers. */
    private function gstin(int $i): string
    {
        return str_pad((string) (($i % 37) + 1), 2, '0', STR_PAD_LEFT)
            . 'DEMO' . str_pad((string) ($i % 9999), 4, '0', STR_PAD_LEFT) . 'D1Z' . ($i % 10);
    }

    private function pan(int $i): string
    {
        return 'DEMOP' . str_pad((string) ($i % 9999), 4, '0', STR_PAD_LEFT) . chr(65 + $i % 26);
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

    /**
     * Generate basic (non-admin) staff login users, password "password".
     *
     * Names are unique: first/last pairs are enumerated without repeats (400
     * combos), and any name already present for the tenant gets a numeric
     * suffix ("Amit Sharma 2"). Emails derive from the final name plus the
     * tenant id (users.email unique index spans tenants), so they are unique
     * and readable; a clashing row is skipped rather than crashing the run.
     * Each user gets 1–3 of the tenant's non-admin roles.
     */
    private function makeUsers(int $count): int
    {
        $tenantId = app(TenantContext::class)->id();

        // Assignable roles, minus top-level ones — these are "basic" users.
        // Tenant roles are seeded as global templates (tenant_id NULL) under
        // spatie teams; also accept any roles created just for this tenant.
        $roleNames = Role::query()
            ->where('guard_name', 'web')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->pluck('name')
            ->reject(fn ($name) => preg_match('/admin|owner|ceo|director/i', $name))
            ->values()
            ->all();
        $roleCount = count($roleNames);

        $password = Hash::make('password');
        $firstCount = count($this->first);
        $lastCount = count($this->last);
        // Names already taken for this tenant (users + earlier demo runs).
        $takenNames = User::pluck('name')->flip()->all();

        $made = 0;
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $batch = min(self::CHUNK, $count - $start);
            DB::transaction(function () use (
                $tenantId, $roleNames, $roleCount, $password, $firstCount, $lastCount, &$takenNames, $start, $batch, &$made
            ) {
                for ($k = 0; $k < $batch; $k++) {
                    $i = $start + $k;

                    // Enumerate first/last pairs without repeating: unique for
                    // the first first×last (400) users in a run.
                    $pair = $i % ($firstCount * $lastCount);
                    $base = $this->first[$pair % $firstCount] . ' '
                        . $this->last[(intdiv($pair, $firstCount) + $pair) % $lastCount];
                    $name = $base;
                    for ($n = 2; isset($takenNames[$name]); $n++) {
                        $name = $base . ' ' . $n;
                    }
                    $takenNames[$name] = true;

                    $email = Str::slug($name, '.') . ".t{$tenantId}@example.com";
                    if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                        continue;
                    }

                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'phone' => $this->phone($i),
                        'password' => $password,
                        'is_active' => true,
                    ]);

                    // 1–3 roles per user, spread across the role list.
                    if ($roleCount > 0) {
                        $roles = [];
                        $take = min(1 + ($i % 3), $roleCount);
                        for ($r = 0; $r < $take; $r++) {
                            $roles[] = $roleNames[($i * 3 + $r * 7) % $roleCount];
                        }
                        $user->syncRoles(array_values(array_unique($roles)));
                    }
                    $made++;
                }
            });
        }

        return $made;
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
