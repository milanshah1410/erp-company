<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountBookController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\B2bOrderController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\GoogleMapsController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\DemoDataController;
use App\Http\Controllers\Api\DataImportController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\EcommerceIntegrationController;
use App\Http\Controllers\Api\ChannelOrderController;
use App\Http\Controllers\Api\ChannelListingController;
use App\Http\Controllers\Api\ChannelSyncLogController;
use App\Http\Controllers\Api\Portal\PortalAuthController;
use App\Http\Controllers\Api\Portal\PortalCatalogueController;
use App\Http\Controllers\Api\Portal\PortalOrderController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\FinancialStatementController;
use App\Http\Controllers\Api\GstController;
use App\Http\Controllers\Api\GstReportController;
use App\Http\Controllers\Api\HsnCodeController;
use App\Http\Controllers\Api\EInvoiceController;
use App\Http\Controllers\Api\EWayBillController;
use App\Http\Controllers\Api\TaxRegisterController;
use App\Http\Controllers\Api\TdsTcsRateController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\BarcodeController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\BomController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrokerCommissionRateController;
use App\Http\Controllers\Api\BrokerController;
use App\Http\Controllers\Api\BrokerReportController;
use App\Http\Controllers\Api\BrokerSettlementController;
use App\Http\Controllers\Api\BrokerWalletController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContextController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CustomerAddressController;
use App\Http\Controllers\Api\CustomerCategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerPriceListController;
use App\Http\Controllers\Api\CustomerVisitController;
use App\Http\Controllers\Api\FollowUpController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\WhatsappController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\MarketingCampaignController;
use App\Http\Controllers\Api\CycleCountController;
use App\Http\Controllers\Api\EmbroideryPaymentController;
use App\Http\Controllers\Api\EmbroideryVendorController;
use App\Http\Controllers\Api\EmbroideryWorkController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\EmployeeClaimController;
use App\Http\Controllers\Api\VehicleExpenseController;
use App\Http\Controllers\Api\FuelExpenseController;
use App\Http\Controllers\Api\ElectricityBillController;
use App\Http\Controllers\Api\FactoryMaintenanceController;
use App\Http\Controllers\Api\AssetMaintenanceController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DeliveryRouteController;
use App\Http\Controllers\Api\DispatchPlanController;
use App\Http\Controllers\Api\ProofOfDeliveryController;
use App\Http\Controllers\Api\TransportChargeController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\PortController;
use App\Http\Controllers\Api\ShippingLineController;
use App\Http\Controllers\Api\AirlineController;
use App\Http\Controllers\Api\FreightForwarderController;
use App\Http\Controllers\Api\ChaAgentController;
use App\Http\Controllers\Api\TransporterController;
use App\Http\Controllers\Api\ImportExportController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShipmentContainerController;
use App\Http\Controllers\Api\ShipmentDocumentController;
use App\Http\Controllers\Api\ShipmentExpenseController;
use App\Http\Controllers\Api\ShipmentIntegrationController;
use App\Http\Controllers\Api\ShipmentPackingItemController;
use App\Http\Controllers\Api\ShipmentPaymentController;
use App\Http\Controllers\Api\ShipmentReportController;
use App\Http\Controllers\Api\FinancialYearController;
use App\Http\Controllers\Api\GateEntryController;
use App\Http\Controllers\Api\GstRegistrationController;
use App\Http\Controllers\Api\GoodsReceiptController;
use App\Http\Controllers\Api\InventoryAlertController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JobWorkReportController;
use App\Http\Controllers\Api\LedgerReportController;
use App\Http\Controllers\Api\ManufacturingOrderController;
use App\Http\Controllers\Api\ManufacturingReportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PaymentGatewayAccountController;
use App\Http\Controllers\Api\PaymentTransactionController;
use App\Http\Controllers\Api\PaymentVoucherController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\PrinterTestController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\EyePrescriptionController;
use App\Http\Controllers\Api\KitchenOrderTicketController;
use App\Http\Controllers\Api\RestaurantOrderController;
use App\Http\Controllers\Api\RestaurantTableController;
use App\Http\Controllers\Api\MetalRateController;
use App\Http\Controllers\Api\OpticalOrderController;
use App\Http\Controllers\Api\OldGoldReceiptController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectTaskController;
use App\Http\Controllers\Api\ProjectTimesheetController;
use App\Http\Controllers\Api\PurchaseIndentController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseReportController;
use App\Http\Controllers\Api\RfqController;
use App\Http\Controllers\Api\ReorderController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SalesmanCollectionController;
use App\Http\Controllers\Api\SalesmanController;
use App\Http\Controllers\Api\SalesmanExpenseController;
use App\Http\Controllers\Api\SalesmanLocationController;
use App\Http\Controllers\Api\SalesmanReportController;
use App\Http\Controllers\Api\SalesmanTargetController;
use App\Http\Controllers\Api\SalesmanVisitController;
use App\Http\Controllers\Api\BeatController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\SalesQuotationController;
use App\Http\Controllers\Api\SalesReportController;
use App\Http\Controllers\Api\SalesReturnController;
use App\Http\Controllers\Api\SerialNumberController;
use App\Http\Controllers\Api\ServiceContractController;
use App\Http\Controllers\Api\ServiceJobController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockLedgerController;
use App\Http\Controllers\Api\StockReturnController;
use App\Http\Controllers\Api\JobWorkController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\TailorController;
use App\Http\Controllers\Api\TailorAlterationController;
use App\Http\Controllers\Api\TailorBonusController;
use App\Http\Controllers\Api\TailorPaymentController;
use App\Http\Controllers\Api\TailorPerformanceController;
use App\Http\Controllers\Api\TailorWorkController;
use App\Http\Controllers\Api\PieceRateController;
use App\Http\Controllers\Api\RateCardController;
use App\Http\Controllers\Api\ColourPrintingController;
use App\Http\Controllers\Api\FabricRollController;
use App\Http\Controllers\Api\FabricInspectionController;
use App\Http\Controllers\Api\ShrinkageRecordController;
use App\Http\Controllers\Api\FabricCuttingController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorRatingController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseLocationController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\LeaveApplicationController;
use App\Http\Controllers\Api\OvertimeRecordController;
use App\Http\Controllers\Api\EmployeeBonusController;
use App\Http\Controllers\Api\SalaryAdvanceController;
use App\Http\Controllers\Api\EmployeeLoanController;
use App\Http\Controllers\Api\BiometricDeviceController;
use App\Http\Controllers\Api\BiometricPunchController;
use App\Http\Controllers\Api\SalaryStructureController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant (business) API routes  —  <APP_URL>/api/...
|--------------------------------------------------------------------------
|
| Shared-database, single-domain multi-tenancy: the tenant is resolved from
| the authenticated user (ResolveTenant middleware, which runs after
| auth:sanctum), which activates the row-level tenant scope so every query is
| constrained to that tenant. Central-only routes (registration, plans,
| provisioning status, billing webhooks) live in routes/api.php on disjoint
| URIs so the two route files never collide.
|
| Loaded by bootstrap/app.php via the withRouting `then` callback.
|
*/

Route::middleware('api')->prefix('api')->group(function () {
    // Public: login (runs before any tenant context exists).
    Route::post('/login', [AuthController::class, 'login']);

    /*
    |----------------------------------------------------------------------
    | B2B customer portal (SRS §14): dealers / distributors / retailers sign
    | in as a Customer tokenable and place their own orders. Login is public;
    | the rest is gated by `portal` (must be a Customer tokenable) on top of
    | the usual tenant-resolution stack.
    |----------------------------------------------------------------------
    */
    Route::post('/portal/login', [PortalAuthController::class, 'login']);
    // OTP login (SRS §18): request a code to the registered phone, then verify.
    Route::post('/portal/login/otp/request', [PortalAuthController::class, 'requestOtp']);
    Route::post('/portal/login/otp', [PortalAuthController::class, 'loginWithOtp']);

    Route::middleware(['auth:sanctum', 'resolve.tenant', 'tenant.active', 'portal'])
        ->prefix('portal')->group(function () {
            Route::get('me', [PortalAuthController::class, 'me']);
            Route::post('logout', [PortalAuthController::class, 'logout']);

            Route::get('catalogue', [PortalCatalogueController::class, 'index']);
            Route::get('catalogue/{product}', [PortalCatalogueController::class, 'show']);
            Route::get('price-list', [PortalCatalogueController::class, 'priceList']);

            Route::get('orders', [PortalOrderController::class, 'index']);
            Route::post('orders', [PortalOrderController::class, 'store']);
            Route::get('orders/{salesOrder}', [PortalOrderController::class, 'show']);
            Route::post('orders/{salesOrder}/cancel', [PortalOrderController::class, 'cancel']);
        });

    Route::middleware(['auth:sanctum', 'resolve.tenant', 'tenant.active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Own profile (no permission gate — everyone maintains their own record).
        // Managing *other* users stays on /users behind the users.* permissions.
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::match(['put', 'patch'], '/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar']);

        // Global omni-search (SRS §22): products + customers + invoices, each
        // scoped to the current tenant and gated per-group by the caller's
        // module view permissions inside the controller.
        Route::get('/search', [GlobalSearchController::class, 'index']);

        // Dynamic navigation (project-stack.md §3): the DB-driven menu tree
        // filtered for this user (role + subscription) plus favorites/recents.
        // No permission gate — the payload is already filtered per-user.
        Route::get('/menu', [MenuController::class, 'index']);
        Route::post('/menu/favorites', [MenuController::class, 'toggleFavorite']);
        Route::post('/menu/recents', [MenuController::class, 'trackRecent']);

        // Google Places proxy (address autocomplete on warehouse/customer
        // forms). Key stays server-side; no permission gate — any signed-in
        // tenant user filling an address form may use it.
        Route::get('/google/place-autocomplete', [GoogleMapsController::class, 'autocomplete']);
        Route::get('/google/place-details', [GoogleMapsController::class, 'placeDetails']);

        /**
         * CRUD helper: each action guarded by a "<module>.<action>" permission.
         * Create actions may additionally pass through plan.limit middleware
         * (App\Http\Middleware\EnforcePlanLimit), which blocks creation past
         * the tenant's subscription feature limits with an upgrade prompt.
         */
        $crud = function (string $uri, string $controller, string $module, string $param, ?string $limit = null) {
            Route::get($uri, [$controller, 'index'])->middleware("permission:{$module}.view");
            $store = Route::post($uri, [$controller, 'store'])->middleware("permission:{$module}.create");
            if ($limit) {
                $store->middleware("plan.limit:{$limit}");
            }
            Route::get("{$uri}/{{$param}}", [$controller, 'show'])->middleware("permission:{$module}.view");
            Route::match(['put', 'patch'], "{$uri}/{{$param}}", [$controller, 'update'])->middleware("permission:{$module}.edit");
            Route::delete("{$uri}/{{$param}}", [$controller, 'destroy'])->middleware("permission:{$module}.delete");
        };

        $crud('products', ProductController::class, 'products', 'product', 'products');

        // Product image + variants (nested under the product)
        Route::post('products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('permission:products.edit');
        Route::get('products/{product}/variants', [ProductVariantController::class, 'index'])->middleware('permission:products.view');
        Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->middleware('permission:products.edit');
        Route::match(['put', 'patch'], 'products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->middleware('permission:products.edit');
        Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])->middleware('permission:products.edit');
        Route::get('categories-tree', [CategoryController::class, 'tree'])->middleware('permission:categories.view');
        Route::get('categories/{category}/attributes', [CategoryController::class, 'attributes'])->middleware('permission:categories.view');
        $crud('categories', CategoryController::class, 'categories', 'category');
        $crud('brands', BrandController::class, 'brands', 'brand');
        $crud('units', UnitController::class, 'units', 'unit');
        $crud('warehouses', WarehouseController::class, 'warehouses', 'warehouse', 'warehouses');

        // Sub-location tracking: zones/racks/shelves/bins + put-away stock
        Route::get('warehouses/{warehouse}/locations', [WarehouseLocationController::class, 'index'])->middleware('permission:warehouses.view');
        Route::get('warehouses/{warehouse}/locations-flat', [WarehouseLocationController::class, 'flat'])->middleware('permission:warehouses.view');
        Route::post('warehouses/{warehouse}/locations', [WarehouseLocationController::class, 'store'])->middleware('permission:warehouses.edit');
        Route::match(['put', 'patch'], 'warehouses/{warehouse}/locations/{location}', [WarehouseLocationController::class, 'update'])->middleware('permission:warehouses.edit');
        Route::delete('warehouses/{warehouse}/locations/{location}', [WarehouseLocationController::class, 'destroy'])->middleware('permission:warehouses.edit');
        Route::get('warehouses/{warehouse}/location-stock', [WarehouseLocationController::class, 'stock'])->middleware('permission:stock.view');
        $crud('suppliers', SupplierController::class, 'suppliers', 'supplier');
        Route::get('suppliers/{supplier}/purchase-history', [SupplierController::class, 'purchaseHistory'])->middleware('permission:suppliers.view');
        Route::get('suppliers/{supplier}/ledger', [SupplierController::class, 'ledger'])->middleware('permission:suppliers.view');
        Route::get('suppliers/{supplier}/payments', [SupplierController::class, 'paymentHistory'])->middleware('permission:suppliers.view');
        $crud('customer-categories', CustomerCategoryController::class, 'customer-categories', 'customerCategory');
        // Upcoming birthdays/anniversaries — must precede the /{customer} wildcard.
        Route::get('customers/occasions', [CustomerController::class, 'occasions'])->middleware('permission:customers.view');
        $crud('customers', CustomerController::class, 'customers', 'customer');
        // B2B portal access: enable/disable login + (re)set the portal password.
        Route::post('customers/{customer}/portal-access', [CustomerController::class, 'setPortalAccess'])->middleware('permission:customers.edit');
        Route::get('customers/{customer}/purchase-history', [CustomerController::class, 'purchaseHistory'])->middleware('permission:customers.view');
        Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger'])->middleware('permission:customers.view');
        Route::get('customers/{customer}/payments', [CustomerController::class, 'paymentHistory'])->middleware('permission:customers.view');

        // Customer billing / shipping addresses (nested)
        Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index'])->middleware('permission:customers.view');
        Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])->middleware('permission:customers.edit');
        Route::match(['put', 'patch'], 'customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])->middleware('permission:customers.edit');
        Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])->middleware('permission:customers.edit');

        // Customer-specific price overrides (nested) + price resolver
        Route::get('customers/{customer}/price-list', [CustomerPriceListController::class, 'index'])->middleware('permission:customers.view');
        Route::get('customers/{customer}/price-for', [CustomerPriceListController::class, 'resolve'])->middleware('permission:customers.view');
        Route::post('customers/{customer}/price-list', [CustomerPriceListController::class, 'store'])->middleware('permission:customers.edit');
        Route::match(['put', 'patch'], 'customers/{customer}/price-list/{priceList}', [CustomerPriceListController::class, 'update'])->middleware('permission:customers.edit');
        Route::delete('customers/{customer}/price-list/{priceList}', [CustomerPriceListController::class, 'destroy'])->middleware('permission:customers.edit');

        // Customer loyalty points: per-customer ledger + earn/redeem/adjust entries
        Route::get('customers/{customer}/loyalty', [LoyaltyController::class, 'index'])->middleware('permission:loyalty.view');
        Route::post('customers/{customer}/loyalty', [LoyaltyController::class, 'store'])->middleware('permission:loyalty.create');

        // The programme itself: who holds points, and the rules they were earned
        // under. `config` is also what the POS redeem box reads, so it sits on
        // the view permission rather than the settings one.
        Route::get('loyalty/members', [LoyaltyController::class, 'members'])->middleware('permission:loyalty.view');
        Route::get('loyalty/config', [LoyaltyController::class, 'config'])->middleware('permission:loyalty.view');

        // Customer visit history (nested)
        Route::get('customers/{customer}/visits', [CustomerVisitController::class, 'index'])->middleware('permission:customers.view');
        Route::post('customers/{customer}/visits', [CustomerVisitController::class, 'store'])->middleware('permission:customers.edit');
        Route::match(['put', 'patch'], 'customers/{customer}/visits/{visit}', [CustomerVisitController::class, 'update'])->middleware('permission:customers.edit');
        Route::delete('customers/{customer}/visits/{visit}', [CustomerVisitController::class, 'destroy'])->middleware('permission:customers.edit');
        $crud('price-lists', PriceListController::class, 'price-lists', 'priceList');

        // Brokers (Dalal): master + commission ledger + settlements
        $crud('brokers', BrokerController::class, 'brokers', 'broker');
        Route::get('brokers/{broker}/ledger', [BrokerController::class, 'ledger'])->middleware('permission:brokers.view');
        Route::get('brokers/{broker}/statement', [BrokerReportController::class, 'monthlyStatement'])->middleware('permission:brokers.view');
        Route::get('brokers/{broker}/settlements', [BrokerController::class, 'settlements'])->middleware('permission:brokers.view');
        Route::post('brokers/{broker}/settlements', [BrokerSettlementController::class, 'store'])->middleware('permission:brokers.create');

        // Per-broker commission rate overrides (by product / customer)
        Route::get('brokers/{broker}/rates', [BrokerCommissionRateController::class, 'index'])->middleware('permission:brokers.view');
        Route::post('brokers/{broker}/rates', [BrokerCommissionRateController::class, 'store'])->middleware('permission:brokers.edit');
        Route::match(['put', 'patch'], 'brokers/{broker}/rates/{rate}', [BrokerCommissionRateController::class, 'update'])->middleware('permission:brokers.edit');
        Route::delete('brokers/{broker}/rates/{rate}', [BrokerCommissionRateController::class, 'destroy'])->middleware('permission:brokers.edit');

        // Broker wallet: running balance + manual top-up / advance / adjustment
        Route::get('brokers/{broker}/wallet', [BrokerWalletController::class, 'index'])->middleware('permission:brokers.view');
        Route::post('brokers/{broker}/wallet', [BrokerWalletController::class, 'store'])->middleware('permission:brokers.create');

        /*
        |----------------------------------------------------------------------
        | Sales & CRM: leads pipeline (with convert-to-customer), follow-up
        | reminders on leads/customers, and discount coupons.
        |----------------------------------------------------------------------
        */
        $crud('leads', LeadController::class, 'leads', 'lead');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('permission:leads.edit');

        $crud('follow-ups', FollowUpController::class, 'follow-ups', 'followUp');
        Route::post('follow-ups/{followUp}/complete', [FollowUpController::class, 'complete'])->middleware('permission:follow-ups.edit');

        // Coupons: validate previews the discount; redeem consumes a use.
        Route::get('coupons/validate', [CouponController::class, 'validateCoupon'])->middleware('permission:coupons.view');
        $crud('coupons', CouponController::class, 'coupons', 'coupon');
        Route::post('coupons/{coupon}/redeem', [CouponController::class, 'redeem'])->middleware('permission:coupons.edit');

        // Referrals: referrer → referred person, convert on join, reward via loyalty points.
        $crud('referrals', ReferralController::class, 'referrals', 'referral');
        Route::post('referrals/{referral}/joined', [ReferralController::class, 'markJoined'])->middleware('permission:referrals.edit');
        Route::post('referrals/{referral}/reward', [ReferralController::class, 'reward'])->middleware('permission:referrals.edit');

        // WhatsApp: click-to-chat link builder + outbound message log.
        Route::get('whatsapp/messages', [WhatsappController::class, 'index'])->middleware('permission:whatsapp.view');
        Route::post('whatsapp/messages', [WhatsappController::class, 'store'])->middleware('permission:whatsapp.create');
        Route::delete('whatsapp/messages/{whatsappMessage}', [WhatsappController::class, 'destroy'])->middleware('permission:whatsapp.delete');

        // SMS integration (SRS §18): OTP, invoice/dispatch/payment-reminder/festival
        // sends + the outbound log. Sends go through the configured gateway (log
        // by default, MSG91 when enabled under Settings).
        Route::get('sms/messages', [SmsController::class, 'index'])->middleware('permission:sms.view');
        Route::post('sms/messages', [SmsController::class, 'store'])->middleware('permission:sms.create');
        Route::post('sms/invoices/{invoice}', [SmsController::class, 'invoice'])->middleware('permission:sms.create');
        Route::post('sms/dispatches/{dispatchPlan}', [SmsController::class, 'dispatch'])->middleware('permission:sms.create');
        Route::post('sms/payment-reminder', [SmsController::class, 'paymentReminder'])->middleware('permission:sms.create');
        Route::post('sms/festival', [SmsController::class, 'festival'])->middleware('permission:sms.create');
        Route::post('sms/otp/send', [SmsController::class, 'sendOtp'])->middleware('permission:sms.create');
        Route::post('sms/otp/verify', [SmsController::class, 'verifyOtp'])->middleware('permission:sms.create');
        Route::delete('sms/messages/{smsMessage}', [SmsController::class, 'destroy'])->middleware('permission:sms.delete');

        // Email integration (SRS §19): send invoice / purchase order / quotation
        // PDFs, connection test, and the outbound email log. Sends go through the
        // tenant's SMTP settings (log driver by default) via a queued job.
        Route::get('email/logs', [EmailController::class, 'index'])->middleware('permission:email.view');
        Route::get('email/status', [EmailController::class, 'status'])->middleware('permission:email.view');
        Route::post('email/test', [EmailController::class, 'test'])->middleware('permission:email.create');
        Route::post('email/invoices/{invoice}', [EmailController::class, 'sendInvoice'])->middleware('permission:email.create');
        Route::post('email/purchase-orders/{purchaseOrder}', [EmailController::class, 'sendPurchaseOrder'])->middleware('permission:email.create');
        Route::post('email/quotations/{salesQuotation}', [EmailController::class, 'sendQuotation'])->middleware('permission:email.create');

        // Marketing campaigns: compose + blast to an audience, per-recipient log.
        Route::get('marketing-campaigns', [MarketingCampaignController::class, 'index'])->middleware('permission:marketing-campaigns.view');
        Route::post('marketing-campaigns/preview', [MarketingCampaignController::class, 'preview'])->middleware('permission:marketing-campaigns.view');
        Route::post('marketing-campaigns', [MarketingCampaignController::class, 'store'])->middleware('permission:marketing-campaigns.create');
        Route::get('marketing-campaigns/{marketingCampaign}', [MarketingCampaignController::class, 'show'])->middleware('permission:marketing-campaigns.view');
        Route::match(['put', 'patch'], 'marketing-campaigns/{marketingCampaign}', [MarketingCampaignController::class, 'update'])->middleware('permission:marketing-campaigns.edit');
        Route::delete('marketing-campaigns/{marketingCampaign}', [MarketingCampaignController::class, 'destroy'])->middleware('permission:marketing-campaigns.delete');
        Route::post('marketing-campaigns/{marketingCampaign}/send', [MarketingCampaignController::class, 'send'])->middleware('permission:marketing-campaigns.create');

        /*
        |----------------------------------------------------------------------
        | Tailor (job-work) module: masters, rate cards, piece-rate operations,
        | work issue/completion, payouts and per-tailor ledger.
        |----------------------------------------------------------------------
        */
        $crud('tailors', TailorController::class, 'tailors', 'tailor');
        Route::get('tailors/{tailor}/ledger', [TailorController::class, 'ledger'])->middleware('permission:tailors.view');
        Route::get('tailors/{tailor}/payments', [TailorController::class, 'payments'])->middleware('permission:tailors.view');

        $crud('rate-cards', RateCardController::class, 'rate-cards', 'rateCard');
        $crud('piece-rates', PieceRateController::class, 'piece-rates', 'pieceRate');

        $crud('tailor-works', TailorWorkController::class, 'tailor-works', 'tailorWork');
        Route::post('tailor-works/{tailorWork}/complete', [TailorWorkController::class, 'complete'])->middleware('permission:tailor-works.edit');
        Route::get('tailor-works/{tailorWork}/pdf', [TailorWorkController::class, 'pdf'])->middleware('permission:tailor-works.view');

        Route::get('tailor-payments', [TailorPaymentController::class, 'index'])->middleware('permission:tailor-payments.view');
        Route::post('tailor-payments', [TailorPaymentController::class, 'store'])->middleware('permission:tailor-payments.create');

        // Contractors: subcontractors that group tailors, with a rolled-up position.
        $crud('contractors', ContractorController::class, 'contractors', 'contractor');
        Route::get('contractors/{contractor}/performance', [ContractorController::class, 'performance'])->middleware('permission:contractors.view');
        Route::get('contractors/{contractor}/tailors', [ContractorController::class, 'tailors'])->middleware('permission:contractors.view');

        // Alteration tracking: pieces returned for adjustment after delivery.
        $crud('tailor-alterations', TailorAlterationController::class, 'tailor-alterations', 'tailorAlteration');
        Route::post('tailor-alterations/{tailorAlteration}/complete', [TailorAlterationController::class, 'complete'])->middleware('permission:tailor-alterations.edit');

        // Bonuses: rule-assisted or manual performance payouts (credit on approve).
        Route::get('tailor-bonuses/calculate', [TailorBonusController::class, 'calculate'])->middleware('permission:tailor-bonuses.view');
        $crud('tailor-bonuses', TailorBonusController::class, 'tailor-bonuses', 'tailorBonus');
        Route::post('tailor-bonuses/{tailorBonus}/approve', [TailorBonusController::class, 'approve'])->middleware('permission:tailor-bonuses.edit');

        /*
        |----------------------------------------------------------------------
        | Salesman (field-sales) module: salesman master, monthly targets +
        | incentive, beat/route plans, daily visit plans with GPS check-in,
        | live-location tracking, field collections, daily expenses and reports.
        |----------------------------------------------------------------------
        */
        $crud('salesmen', SalesmanController::class, 'salesmen', 'salesman');
        Route::get('salesmen/{salesman}/performance', [SalesmanController::class, 'performance'])->middleware('permission:salesmen.view');
        Route::get('salesmen/{salesman}/live-location', [SalesmanController::class, 'liveLocation'])->middleware('permission:salesmen.view');
        Route::get('salesmen/{salesman}/collections', [SalesmanController::class, 'collections'])->middleware('permission:salesman-collections.view');

        $crud('salesman-targets', SalesmanTargetController::class, 'salesman-targets', 'salesmanTarget');

        // Beat plan + route stops (managed inline with the beat).
        $crud('beats', BeatController::class, 'beats', 'beat');

        // Daily visit plan + GPS check-in / check-out.
        Route::post('salesman-visits/generate-plan', [SalesmanVisitController::class, 'generatePlan'])->middleware('permission:salesman-visits.create');
        Route::post('salesman-visits/{salesmanVisit}/check-in', [SalesmanVisitController::class, 'checkIn'])->middleware('permission:salesman-visits.edit');
        Route::post('salesman-visits/{salesmanVisit}/check-out', [SalesmanVisitController::class, 'checkOut'])->middleware('permission:salesman-visits.edit');
        $crud('salesman-visits', SalesmanVisitController::class, 'salesman-visits', 'salesmanVisit');

        // GPS tracking: ping ingest, per-salesman track, fleet live map.
        Route::get('salesman-locations/live', [SalesmanLocationController::class, 'live'])->middleware('permission:salesman-locations.view');
        Route::get('salesman-locations', [SalesmanLocationController::class, 'index'])->middleware('permission:salesman-locations.view');
        Route::post('salesman-locations', [SalesmanLocationController::class, 'store'])->middleware('permission:salesman-locations.create');

        // Field collections: create, deposit, bounce.
        Route::post('salesman-collections/{salesmanCollection}/deposit', [SalesmanCollectionController::class, 'deposit'])->middleware('permission:salesman-collections.edit');
        Route::post('salesman-collections/{salesmanCollection}/bounce', [SalesmanCollectionController::class, 'bounce'])->middleware('permission:salesman-collections.edit');
        $crud('salesman-collections', SalesmanCollectionController::class, 'salesman-collections', 'salesmanCollection');

        // Daily field expenses with approval workflow.
        Route::post('salesman-expenses/{salesmanExpense}/approve', [SalesmanExpenseController::class, 'approve'])->middleware('permission:salesman-expenses.edit');
        Route::post('salesman-expenses/{salesmanExpense}/reject', [SalesmanExpenseController::class, 'reject'])->middleware('permission:salesman-expenses.edit');
        $crud('salesman-expenses', SalesmanExpenseController::class, 'salesman-expenses', 'salesmanExpense');

        // Reports: team incentive/achievement and visit compliance. Module-gated
        // like the rest of the reports so a tenant without Field Sales can't read
        // them from a bookmarked URL.
        Route::get('salesman-reports/incentive', [SalesmanReportController::class, 'incentive'])->middleware(['permission:salesmen.view', 'module:field_sales']);
        Route::get('salesman-reports/visit-compliance', [SalesmanReportController::class, 'visitCompliance'])->middleware(['permission:salesmen.view', 'module:field_sales']);

        /*
        |----------------------------------------------------------------------
        | Embroidery (job-work) module: vendor master, dispatch/receive job
        | cards, payouts and per-vendor ledger.
        |----------------------------------------------------------------------
        */
        $crud('embroidery-vendors', EmbroideryVendorController::class, 'embroidery-vendors', 'embroideryVendor');
        Route::get('embroidery-vendors/{embroideryVendor}/ledger', [EmbroideryVendorController::class, 'ledger'])->middleware('permission:embroidery-vendors.view');
        Route::get('embroidery-vendors/{embroideryVendor}/payments', [EmbroideryVendorController::class, 'payments'])->middleware('permission:embroidery-vendors.view');

        $crud('embroidery-works', EmbroideryWorkController::class, 'embroidery-works', 'embroideryWork');
        Route::post('embroidery-works/{embroideryWork}/receive', [EmbroideryWorkController::class, 'receive'])->middleware('permission:embroidery-works.edit');

        Route::get('embroidery-payments', [EmbroideryPaymentController::class, 'index'])->middleware('permission:embroidery-payments.view');
        Route::post('embroidery-payments', [EmbroideryPaymentController::class, 'store'])->middleware('permission:embroidery-payments.create');

        // Colour Printing: printing jobs sent to external printers (vendors).
        $crud('colour-printings', ColourPrintingController::class, 'colour-printing', 'colourPrinting');

        /*
        |----------------------------------------------------------------------
        | Unified payments: customer receipts (inbound) and supplier/vendor/
        | expense payouts (outbound), in any mode, optionally allocated across
        | open invoices. Advances stay unallocated as a party ledger credit and
        | are applied later via the /allocations endpoint.
        |----------------------------------------------------------------------
        */
        Route::get('payment-vouchers', [PaymentVoucherController::class, 'index'])->middleware('permission:payments.view');
        Route::post('payment-vouchers', [PaymentVoucherController::class, 'store'])->middleware('permission:payments.create');
        Route::get('payment-vouchers/{paymentVoucher}', [PaymentVoucherController::class, 'show'])->middleware('permission:payments.view');
        Route::post('payment-vouchers/{paymentVoucher}/allocations', [PaymentVoucherController::class, 'allocate'])->middleware('permission:payments.edit');
        Route::delete('payment-vouchers/{paymentVoucher}', [PaymentVoucherController::class, 'destroy'])->middleware('permission:payments.delete');

        /*
        |----------------------------------------------------------------------
        | Online payment gateways (SRS §16): tenant gateway config + the payment
        | links (transactions) generated to collect sales invoices online. The
        | customer-facing pay page + gateway callbacks/webhooks are public and
        | live in routes/api.php.
        |----------------------------------------------------------------------
        */
        Route::get('payment-gateways/catalog', [PaymentGatewayAccountController::class, 'catalog'])->middleware('permission:payment-gateways.view');
        Route::get('payment-gateways', [PaymentGatewayAccountController::class, 'index'])->middleware('permission:payment-gateways.view');
        Route::post('payment-gateways', [PaymentGatewayAccountController::class, 'store'])->middleware('permission:payment-gateways.create');
        Route::match(['put', 'patch'], 'payment-gateways/{paymentGateway}', [PaymentGatewayAccountController::class, 'update'])->middleware('permission:payment-gateways.edit');
        Route::delete('payment-gateways/{paymentGateway}', [PaymentGatewayAccountController::class, 'destroy'])->middleware('permission:payment-gateways.delete');

        Route::get('payment-transactions', [PaymentTransactionController::class, 'index'])->middleware('permission:payments.view');
        Route::get('payment-transactions/{paymentTransaction}', [PaymentTransactionController::class, 'show'])->middleware('permission:payments.view');
        Route::post('payment-transactions/{paymentTransaction}/cancel', [PaymentTransactionController::class, 'cancel'])->middleware('permission:payments.edit');
        Route::post('invoices/{invoice}/payment-link', [PaymentTransactionController::class, 'createForInvoice'])->middleware('permission:payments.create');

        /*
        |----------------------------------------------------------------------
        | Purchase module
        |----------------------------------------------------------------------
        */
        // Purchase Indents: internal requisitions with an approval gate, then
        // convertible into an RFQ or straight into a purchase order.
        Route::get('purchase-indents', [PurchaseIndentController::class, 'index'])->middleware('permission:purchase-indents.view');
        Route::post('purchase-indents', [PurchaseIndentController::class, 'store'])->middleware('permission:purchase-indents.create');
        Route::get('purchase-indents/{purchaseIndent}', [PurchaseIndentController::class, 'show'])->middleware('permission:purchase-indents.view');
        Route::match(['put', 'patch'], 'purchase-indents/{purchaseIndent}', [PurchaseIndentController::class, 'update'])->middleware('permission:purchase-indents.edit');
        Route::delete('purchase-indents/{purchaseIndent}', [PurchaseIndentController::class, 'destroy'])->middleware('permission:purchase-indents.delete');
        Route::post('purchase-indents/{purchaseIndent}/submit', [PurchaseIndentController::class, 'submit'])->middleware('permission:purchase-indents.edit');
        Route::post('purchase-indents/{purchaseIndent}/approve', [PurchaseIndentController::class, 'approve'])->middleware('permission:purchase-indents.approve');
        Route::post('purchase-indents/{purchaseIndent}/reject', [PurchaseIndentController::class, 'reject'])->middleware('permission:purchase-indents.approve');
        Route::post('purchase-indents/{purchaseIndent}/convert-to-rfq', [PurchaseIndentController::class, 'convertToRfq'])->middleware('permission:rfqs.create');
        Route::post('purchase-indents/{purchaseIndent}/convert-to-po', [PurchaseIndentController::class, 'convertToPo'])->middleware('permission:purchases.create');

        // RFQ (Request for Quotation): invite vendors, capture per-line quotes,
        // compare side-by-side, then award to a vendor to raise the PO.
        Route::get('rfqs', [RfqController::class, 'index'])->middleware('permission:rfqs.view');
        Route::post('rfqs', [RfqController::class, 'store'])->middleware('permission:rfqs.create');
        Route::get('rfqs/{rfq}', [RfqController::class, 'show'])->middleware('permission:rfqs.view');
        Route::get('rfqs/{rfq}/comparison', [RfqController::class, 'comparison'])->middleware('permission:rfqs.view');
        Route::match(['put', 'patch'], 'rfqs/{rfq}', [RfqController::class, 'update'])->middleware('permission:rfqs.edit');
        Route::delete('rfqs/{rfq}', [RfqController::class, 'destroy'])->middleware('permission:rfqs.delete');
        Route::post('rfqs/{rfq}/send', [RfqController::class, 'send'])->middleware('permission:rfqs.edit');
        Route::post('rfqs/{rfq}/suppliers/{rfqSupplier}/quotes', [RfqController::class, 'recordQuote'])->middleware('permission:rfqs.edit');
        Route::post('rfqs/{rfq}/award', [RfqController::class, 'award'])->middleware('permission:purchases.create');
        Route::post('rfqs/{rfq}/close', [RfqController::class, 'close'])->middleware('permission:rfqs.edit');

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchases.view');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchases.create');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchases.view');
        Route::get('purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'pdf'])->middleware('permission:purchases.view');
        Route::match(['put', 'patch'], 'purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchases.edit');
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchases.delete');
        Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchases.edit');
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchases.approve');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchases.edit');

        // Goods Receipt Notes (posting stock is a create action against the purchase module)
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchases.view');
        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:purchases.create');
        Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchases.view');

        // Vendor Rating: manual quality/delivery/pricing/service scorecards, plus
        // an aggregated per-vendor leaderboard (summary must precede the wildcard).
        Route::get('vendor-ratings/summary', [VendorRatingController::class, 'summary'])->middleware('permission:vendor-ratings.view');
        Route::get('vendor-ratings', [VendorRatingController::class, 'index'])->middleware('permission:vendor-ratings.view');
        Route::post('vendor-ratings', [VendorRatingController::class, 'store'])->middleware('permission:vendor-ratings.create');
        Route::get('vendor-ratings/{vendorRating}', [VendorRatingController::class, 'show'])->middleware('permission:vendor-ratings.view');
        Route::delete('vendor-ratings/{vendorRating}', [VendorRatingController::class, 'destroy'])->middleware('permission:vendor-ratings.delete');

        // Vendor invoices: 3-way match against PO + GRN, soft warning on exception
        Route::get('purchase-invoices', [PurchaseInvoiceController::class, 'index'])->middleware('permission:purchase-invoices.view');
        Route::post('purchase-invoices', [PurchaseInvoiceController::class, 'store'])->middleware('permission:purchase-invoices.create');
        Route::get('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'show'])->middleware('permission:purchase-invoices.view');
        Route::get('purchase-invoices/{purchaseInvoice}/pdf', [PurchaseInvoiceController::class, 'pdf'])->middleware('permission:purchase-invoices.view');
        Route::match(['put', 'patch'], 'purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'update'])->middleware('permission:purchase-invoices.edit');
        Route::delete('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'destroy'])->middleware('permission:purchase-invoices.delete');
        Route::post('purchase-invoices/{purchaseInvoice}/approve', [PurchaseInvoiceController::class, 'approve'])->middleware('permission:purchase-invoices.approve');
        Route::post('purchase-invoices/{purchaseInvoice}/cancel', [PurchaseInvoiceController::class, 'cancel'])->middleware('permission:purchase-invoices.edit');
        Route::post('purchase-invoices/{purchaseInvoice}/payments', [PurchaseInvoiceController::class, 'recordPayment'])->middleware('permission:purchase-invoices.create');

        /*
        |----------------------------------------------------------------------
        | Sales module
        |----------------------------------------------------------------------
        */
        Route::get('sales-orders', [SalesOrderController::class, 'index'])->middleware('permission:sales.view');
        Route::post('sales-orders', [SalesOrderController::class, 'store'])->middleware('permission:sales.create');
        Route::get('sales-orders/{salesOrder}', [SalesOrderController::class, 'show'])->middleware('permission:sales.view');
        Route::get('sales-orders/{salesOrder}/delivery-challan', [SalesOrderController::class, 'pdf'])->middleware('permission:sales.view');
        Route::match(['put', 'patch'], 'sales-orders/{salesOrder}', [SalesOrderController::class, 'update'])->middleware('permission:sales.edit');
        Route::delete('sales-orders/{salesOrder}', [SalesOrderController::class, 'destroy'])->middleware('permission:sales.delete');
        Route::post('sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:sales.approve');
        Route::post('sales-orders/{salesOrder}/recheck', [SalesOrderController::class, 'recheck'])->middleware('permission:sales.approve');
        Route::post('sales-orders/{salesOrder}/pick', [SalesOrderController::class, 'pick'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/pack', [SalesOrderController::class, 'pack'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/ship', [SalesOrderController::class, 'ship'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/deliver', [SalesOrderController::class, 'deliver'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales.edit');

        // B2B order approval queue (SRS §14): portal orders land here for staff
        // to approve (credit-check + reserve, then confirm) or reject.
        Route::get('b2b-orders', [B2bOrderController::class, 'index'])->middleware('permission:b2b-orders.view');
        Route::get('b2b-orders/{salesOrder}', [B2bOrderController::class, 'show'])->middleware('permission:b2b-orders.view');
        Route::post('b2b-orders/{salesOrder}/approve', [B2bOrderController::class, 'approve'])->middleware('permission:b2b-orders.approve');
        Route::post('b2b-orders/{salesOrder}/reject', [B2bOrderController::class, 'reject'])->middleware('permission:b2b-orders.approve');

        /*
        |----------------------------------------------------------------------
        | E-commerce Integration (SRS §15): connect marketplaces/storefronts
        | (Shopify, WooCommerce, Magento, Amazon, Flipkart, Meesho, IndiaMART),
        | pull orders into the inbox, map listings, and push inventory.
        |----------------------------------------------------------------------
        */
        Route::get('ecommerce/platforms', [EcommerceIntegrationController::class, 'platforms'])->middleware('permission:ecommerce-integrations.view');
        Route::get('ecommerce-integrations', [EcommerceIntegrationController::class, 'index'])->middleware('permission:ecommerce-integrations.view');
        Route::post('ecommerce-integrations', [EcommerceIntegrationController::class, 'store'])->middleware('permission:ecommerce-integrations.create');
        Route::get('ecommerce-integrations/{ecommerceIntegration}', [EcommerceIntegrationController::class, 'show'])->middleware('permission:ecommerce-integrations.view');
        Route::match(['put', 'patch'], 'ecommerce-integrations/{ecommerceIntegration}', [EcommerceIntegrationController::class, 'update'])->middleware('permission:ecommerce-integrations.edit');
        Route::delete('ecommerce-integrations/{ecommerceIntegration}', [EcommerceIntegrationController::class, 'destroy'])->middleware('permission:ecommerce-integrations.delete');
        Route::post('ecommerce-integrations/{ecommerceIntegration}/test', [EcommerceIntegrationController::class, 'test'])->middleware('permission:ecommerce-integrations.edit');
        Route::post('ecommerce-integrations/{ecommerceIntegration}/sync-orders', [EcommerceIntegrationController::class, 'syncOrders'])->middleware('permission:ecommerce-integrations.edit');
        Route::post('ecommerce-integrations/{ecommerceIntegration}/push-inventory', [EcommerceIntegrationController::class, 'pushInventory'])->middleware('permission:ecommerce-integrations.edit');
        Route::post('ecommerce-integrations/{ecommerceIntegration}/sync-listings', [EcommerceIntegrationController::class, 'syncListings'])->middleware('permission:ecommerce-integrations.edit');
        Route::get('ecommerce-integrations/{ecommerceIntegration}/remote-listings', [ChannelListingController::class, 'remote'])->middleware('permission:channel-listings.view');

        // Marketplace order inbox
        Route::get('channel-orders', [ChannelOrderController::class, 'index'])->middleware('permission:channel-orders.view');
        Route::get('channel-orders/{channelOrder}', [ChannelOrderController::class, 'show'])->middleware('permission:channel-orders.view');
        Route::post('channel-orders/{channelOrder}/import', [ChannelOrderController::class, 'import'])->middleware('permission:channel-orders.approve');
        Route::post('channel-orders/{channelOrder}/ignore', [ChannelOrderController::class, 'ignore'])->middleware('permission:channel-orders.edit');

        // Product ↔ listing mapping
        Route::get('channel-listings', [ChannelListingController::class, 'index'])->middleware('permission:channel-listings.view');
        Route::post('channel-listings', [ChannelListingController::class, 'store'])->middleware('permission:channel-listings.create');
        Route::match(['put', 'patch'], 'channel-listings/{channelListing}', [ChannelListingController::class, 'update'])->middleware('permission:channel-listings.edit');
        Route::delete('channel-listings/{channelListing}', [ChannelListingController::class, 'destroy'])->middleware('permission:channel-listings.delete');

        // Sync run history (read-only)
        Route::get('channel-sync-logs', [ChannelSyncLogController::class, 'index'])->middleware('permission:channel-sync-logs.view');

        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:sales.view');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:sales.view');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware('permission:sales.view');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->middleware('permission:sales.create');

        // Sales quotations: pre-order stage, converts into a Sales Order once accepted
        Route::get('sales-quotations', [SalesQuotationController::class, 'index'])->middleware('permission:sales.view');
        Route::post('sales-quotations', [SalesQuotationController::class, 'store'])->middleware('permission:sales.create');
        Route::get('sales-quotations/{salesQuotation}', [SalesQuotationController::class, 'show'])->middleware('permission:sales.view');
        Route::get('sales-quotations/{salesQuotation}/pdf', [SalesQuotationController::class, 'pdf'])->middleware('permission:sales.view');
        Route::match(['put', 'patch'], 'sales-quotations/{salesQuotation}', [SalesQuotationController::class, 'update'])->middleware('permission:sales.edit');
        Route::delete('sales-quotations/{salesQuotation}', [SalesQuotationController::class, 'destroy'])->middleware('permission:sales.delete');
        Route::post('sales-quotations/{salesQuotation}/send', [SalesQuotationController::class, 'send'])->middleware('permission:sales.edit');
        Route::post('sales-quotations/{salesQuotation}/accept', [SalesQuotationController::class, 'accept'])->middleware('permission:sales.edit');
        Route::post('sales-quotations/{salesQuotation}/reject', [SalesQuotationController::class, 'reject'])->middleware('permission:sales.edit');
        Route::post('sales-quotations/{salesQuotation}/convert', [SalesQuotationController::class, 'convert'])->middleware('permission:sales.create');

        // POS: one-shot retail counter checkout (creates SO + Invoice + Payments atomically)
        Route::get('pos/products', [PosController::class, 'products'])->middleware('permission:sales.view');
        Route::get('pos/lookup', [PosController::class, 'lookup'])->middleware('permission:sales.view');
        Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware('permission:sales.create');
        // What the till may offer this customer in points. On sales.view, not
        // loyalty.view: a cashier reads it as part of ringing up a bill.
        Route::get('pos/loyalty-quote', [PosController::class, 'loyaltyQuote'])->middleware('permission:sales.view');
        // Ask the cart whether it needs a prescription before the customer has
        // queued to pay, rather than refusing the sale at the last step.
        Route::post('pos/prescription-check', [PosController::class, 'prescriptionCheck'])->middleware('permission:sales.view');
        // Counter collection by QR: raise a link against the bill, then poll it.
        // Creating one is a payment act, reading its state is part of billing.
        Route::post('pos/collect-online', [PosController::class, 'collectOnline'])->middleware('permission:payments.create');
        Route::get('pos/collect-online/{token}', [PosController::class, 'collectStatus'])->middleware('permission:sales.view');
        // POS invoice lookup + counter return / exchange flows
        Route::get('pos/invoices', [PosController::class, 'invoices'])->middleware('permission:sales.view');
        Route::get('pos/invoices/{invoice}', [PosController::class, 'invoice'])->middleware('permission:sales.view');
        Route::post('pos/return', [PosController::class, 'processReturn'])->middleware('permission:sales-returns.create');
        Route::post('pos/exchange', [PosController::class, 'exchange'])->middleware('permission:sales.create');

        // Sales returns / credit notes: tied to a specific invoice, restocks + credits the customer
        Route::get('sales-returns', [SalesReturnController::class, 'index'])->middleware('permission:sales-returns.view');
        Route::post('sales-returns', [SalesReturnController::class, 'store'])->middleware('permission:sales-returns.create');
        Route::get('sales-returns/{salesReturn}', [SalesReturnController::class, 'show'])->middleware('permission:sales-returns.view');
        Route::match(['put', 'patch'], 'sales-returns/{salesReturn}', [SalesReturnController::class, 'update'])->middleware('permission:sales-returns.edit');
        Route::delete('sales-returns/{salesReturn}', [SalesReturnController::class, 'destroy'])->middleware('permission:sales-returns.delete');
        Route::post('sales-returns/{salesReturn}/approve', [SalesReturnController::class, 'approve'])->middleware('permission:sales-returns.approve');
        Route::post('sales-returns/{salesReturn}/cancel', [SalesReturnController::class, 'cancel'])->middleware('permission:sales-returns.edit');

        /*
        |----------------------------------------------------------------------
        | Manufacturing: production orders that consume raw materials and
        | produce finished goods, tracked through a chain of stages.
        |----------------------------------------------------------------------
        */
        // Bills of Materials — reusable component recipes for finished products.
        Route::get('boms', [BomController::class, 'index'])->middleware('permission:boms.view');
        Route::post('boms', [BomController::class, 'store'])->middleware('permission:boms.create');
        Route::get('boms/{bom}', [BomController::class, 'show'])->middleware('permission:boms.view');
        Route::get('boms/{bom}/explode', [BomController::class, 'explode'])->middleware('permission:boms.view');
        Route::match(['put', 'patch'], 'boms/{bom}', [BomController::class, 'update'])->middleware('permission:boms.edit');
        Route::delete('boms/{bom}', [BomController::class, 'destroy'])->middleware('permission:boms.delete');

        Route::get('manufacturing-orders', [ManufacturingOrderController::class, 'index'])->middleware('permission:manufacturing-orders.view');
        Route::post('manufacturing-orders', [ManufacturingOrderController::class, 'store'])->middleware('permission:manufacturing-orders.create');
        Route::get('manufacturing-orders/{manufacturingOrder}', [ManufacturingOrderController::class, 'show'])->middleware('permission:manufacturing-orders.view');
        Route::get('manufacturing-orders/{manufacturingOrder}/pdf', [ManufacturingOrderController::class, 'pdf'])->middleware('permission:manufacturing-orders.view');
        Route::match(['put', 'patch'], 'manufacturing-orders/{manufacturingOrder}', [ManufacturingOrderController::class, 'update'])->middleware('permission:manufacturing-orders.edit');
        Route::delete('manufacturing-orders/{manufacturingOrder}', [ManufacturingOrderController::class, 'destroy'])->middleware('permission:manufacturing-orders.delete');
        Route::post('manufacturing-orders/{manufacturingOrder}/start', [ManufacturingOrderController::class, 'start'])->middleware('permission:manufacturing-orders.edit');
        Route::post('manufacturing-orders/{manufacturingOrder}/complete', [ManufacturingOrderController::class, 'complete'])->middleware('permission:manufacturing-orders.edit');
        Route::post('manufacturing-orders/{manufacturingOrder}/cancel', [ManufacturingOrderController::class, 'cancel'])->middleware('permission:manufacturing-orders.edit');
        Route::match(['put', 'patch'], 'manufacturing-orders/{manufacturingOrder}/stages/{stage}', [ManufacturingOrderController::class, 'updateStage'])->middleware('permission:manufacturing-orders.edit');
        Route::post('manufacturing-orders/{manufacturingOrder}/stages/{stage}/image', [ManufacturingOrderController::class, 'uploadStageImage'])->middleware('permission:manufacturing-orders.edit');

        /*
        |----------------------------------------------------------------------
        | Inventory: current stock + immutable ledger (read-only)
        |----------------------------------------------------------------------
        */
        Route::get('stock', [StockController::class, 'index'])->middleware('permission:stock.view');
        Route::get('stock-ledger', [StockLedgerController::class, 'index'])->middleware('permission:stock-ledger.view');

        // Stock adjustments: reason-coded corrections with approval workflow
        Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware('permission:stock-adjustments.view');
        Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('permission:stock-adjustments.create');
        Route::get('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->middleware('permission:stock-adjustments.view');
        Route::match(['put', 'patch'], 'stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->middleware('permission:stock-adjustments.edit');
        Route::delete('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'destroy'])->middleware('permission:stock-adjustments.delete');
        Route::post('stock-adjustments/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve'])->middleware('permission:stock-adjustments.approve');
        Route::post('stock-adjustments/{stockAdjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->middleware('permission:stock-adjustments.edit');

        // Returns inward: stock coming back into a warehouse, with approval workflow
        Route::get('stock-returns', [StockReturnController::class, 'index'])->middleware('permission:stock-returns.view');
        Route::post('stock-returns', [StockReturnController::class, 'store'])->middleware('permission:stock-returns.create');
        Route::get('stock-returns/{stockReturn}', [StockReturnController::class, 'show'])->middleware('permission:stock-returns.view');
        Route::match(['put', 'patch'], 'stock-returns/{stockReturn}', [StockReturnController::class, 'update'])->middleware('permission:stock-returns.edit');
        Route::delete('stock-returns/{stockReturn}', [StockReturnController::class, 'destroy'])->middleware('permission:stock-returns.delete');
        Route::post('stock-returns/{stockReturn}/approve', [StockReturnController::class, 'approve'])->middleware('permission:stock-returns.approve');
        Route::post('stock-returns/{stockReturn}/cancel', [StockReturnController::class, 'cancel'])->middleware('permission:stock-returns.edit');

        // Cycle counts: physical stock verification with variance reporting
        Route::get('cycle-counts', [CycleCountController::class, 'index'])->middleware('permission:cycle-counts.view');
        Route::post('cycle-counts', [CycleCountController::class, 'store'])->middleware('permission:cycle-counts.create');
        Route::get('cycle-counts/{cycleCount}', [CycleCountController::class, 'show'])->middleware('permission:cycle-counts.view');
        Route::match(['put', 'patch'], 'cycle-counts/{cycleCount}', [CycleCountController::class, 'update'])->middleware('permission:cycle-counts.edit');
        Route::delete('cycle-counts/{cycleCount}', [CycleCountController::class, 'destroy'])->middleware('permission:cycle-counts.delete');
        Route::post('cycle-counts/{cycleCount}/complete', [CycleCountController::class, 'complete'])->middleware('permission:cycle-counts.approve');
        Route::post('cycle-counts/{cycleCount}/cancel', [CycleCountController::class, 'cancel'])->middleware('permission:cycle-counts.edit');

        // Stock transfers: warehouse A -> B with in-transit tracking
        Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware('permission:stock-transfers.view');
        Route::post('stock-transfers', [StockTransferController::class, 'store'])->middleware('permission:stock-transfers.create');
        Route::get('stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->middleware('permission:stock-transfers.view');
        Route::match(['put', 'patch'], 'stock-transfers/{stockTransfer}', [StockTransferController::class, 'update'])->middleware('permission:stock-transfers.edit');
        Route::delete('stock-transfers/{stockTransfer}', [StockTransferController::class, 'destroy'])->middleware('permission:stock-transfers.delete');
        Route::post('stock-transfers/{stockTransfer}/dispatch', [StockTransferController::class, 'dispatch'])->middleware('permission:stock-transfers.approve');
        Route::post('stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->middleware('permission:stock-transfers.edit');
        Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->middleware('permission:stock-transfers.edit');

        /*
        |----------------------------------------------------------------------
        | Job Work (outside-vendor subcontracting)
        |----------------------------------------------------------------------
        */
        Route::get('job-works/report/pending', [JobWorkController::class, 'pendingReport'])->middleware('permission:job-works.view');
        Route::get('job-works/report/damage', [JobWorkController::class, 'damageReport'])->middleware('permission:job-works.view');
        Route::get('job-works/report/shortage', [JobWorkController::class, 'shortageReport'])->middleware('permission:job-works.view');
        Route::get('job-works', [JobWorkController::class, 'index'])->middleware('permission:job-works.view');
        Route::post('job-works', [JobWorkController::class, 'store'])->middleware('permission:job-works.create');
        Route::get('job-works/{jobWork}', [JobWorkController::class, 'show'])->middleware('permission:job-works.view');
        Route::get('job-works/{jobWork}/pdf', [JobWorkController::class, 'pdf'])->middleware('permission:job-works.view');
        Route::match(['put', 'patch'], 'job-works/{jobWork}', [JobWorkController::class, 'update'])->middleware('permission:job-works.edit');
        Route::delete('job-works/{jobWork}', [JobWorkController::class, 'destroy'])->middleware('permission:job-works.delete');
        Route::post('job-works/{jobWork}/issue', [JobWorkController::class, 'issue'])->middleware('permission:job-works.approve');
        Route::post('job-works/{jobWork}/receive', [JobWorkController::class, 'receive'])->middleware('permission:job-works.edit');
        Route::post('job-works/{jobWork}/close', [JobWorkController::class, 'close'])->middleware('permission:job-works.approve');
        Route::post('job-works/{jobWork}/cancel', [JobWorkController::class, 'cancel'])->middleware('permission:job-works.edit');

        /*
        |----------------------------------------------------------------------
        | Service Management: repair/installation jobs on a customer's item, the
        | spare parts they consume, and the AMC contracts that make some of them
        | free. Every state change is its own endpoint because each carries a
        | side effect (assign stamps a technician, complete prices and invoices,
        | cancel hands a contract visit back).
        |----------------------------------------------------------------------
        */
        $crud('service-contracts', ServiceContractController::class, 'service-contracts', 'serviceContract');

        Route::get('service-jobs', [ServiceJobController::class, 'index'])->middleware('permission:service-jobs.view');
        Route::post('service-jobs', [ServiceJobController::class, 'store'])->middleware('permission:service-jobs.create');
        Route::get('service-jobs/{serviceJob}', [ServiceJobController::class, 'show'])->middleware('permission:service-jobs.view');
        Route::match(['put', 'patch'], 'service-jobs/{serviceJob}', [ServiceJobController::class, 'update'])->middleware('permission:service-jobs.edit');
        Route::delete('service-jobs/{serviceJob}', [ServiceJobController::class, 'destroy'])->middleware('permission:service-jobs.delete');
        Route::post('service-jobs/{serviceJob}/assign', [ServiceJobController::class, 'assign'])->middleware('permission:service-jobs.edit');
        Route::post('service-jobs/{serviceJob}/progress', [ServiceJobController::class, 'progress'])->middleware('permission:service-jobs.edit');
        Route::post('service-jobs/{serviceJob}/issue', [ServiceJobController::class, 'issue'])->middleware('permission:service-jobs.edit');
        // Completing bills the job, so it sits on delete-level authority rather
        // than edit: raising an invoice is not a technician's call.
        Route::post('service-jobs/{serviceJob}/complete', [ServiceJobController::class, 'complete'])->middleware('permission:service-jobs.delete');
        Route::post('service-jobs/{serviceJob}/deliver', [ServiceJobController::class, 'deliver'])->middleware('permission:service-jobs.delete');
        Route::post('service-jobs/{serviceJob}/cancel', [ServiceJobController::class, 'cancel'])->middleware('permission:service-jobs.delete');

        /*
        |----------------------------------------------------------------------
        | Restaurant: the floor, its open tickets and the kitchen display. Items
        | are added, fired and settled through separate endpoints because those
        | are separate acts on the floor — a waiter keys a round, fires it, and
        | comes back twice more before the table asks for the bill.
        |----------------------------------------------------------------------
        */
        Route::get('restaurant/floor', [RestaurantTableController::class, 'floor'])->middleware('permission:restaurant-tables.view');
        Route::get('restaurant/sections', [RestaurantTableController::class, 'sections'])->middleware('permission:restaurant-tables.view');
        Route::post('restaurant/sections', [RestaurantTableController::class, 'storeSection'])->middleware('permission:restaurant-tables.create');
        Route::post('restaurant-tables/{restaurantTable}/status', [RestaurantTableController::class, 'setStatus'])->middleware('permission:restaurant-tables.edit');
        Route::get('restaurant-tables', [RestaurantTableController::class, 'index'])->middleware('permission:restaurant-tables.view');
        Route::post('restaurant-tables', [RestaurantTableController::class, 'store'])->middleware('permission:restaurant-tables.create');
        Route::match(['put', 'patch'], 'restaurant-tables/{restaurantTable}', [RestaurantTableController::class, 'update'])->middleware('permission:restaurant-tables.edit');
        Route::delete('restaurant-tables/{restaurantTable}', [RestaurantTableController::class, 'destroy'])->middleware('permission:restaurant-tables.delete');

        Route::post('restaurant-orders/{restaurantOrder}/items', [RestaurantOrderController::class, 'addItems'])->middleware('permission:restaurant-orders.edit');
        Route::delete('restaurant-orders/{restaurantOrder}/items/{item}', [RestaurantOrderController::class, 'removeItem'])->middleware('permission:restaurant-orders.edit');
        Route::post('restaurant-orders/{restaurantOrder}/items/{item}/void', [RestaurantOrderController::class, 'voidItem'])->middleware('permission:restaurant-orders.delete');
        Route::post('restaurant-orders/{restaurantOrder}/fire', [RestaurantOrderController::class, 'fire'])->middleware('permission:restaurant-orders.edit');
        // Settling raises the invoice and frees the table, so it sits on
        // delete-level authority rather than a waiter's edit rights.
        Route::post('restaurant-orders/{restaurantOrder}/settle', [RestaurantOrderController::class, 'settle'])->middleware('permission:restaurant-orders.delete');
        Route::post('restaurant-orders/{restaurantOrder}/cancel', [RestaurantOrderController::class, 'cancel'])->middleware('permission:restaurant-orders.delete');
        Route::get('restaurant-orders', [RestaurantOrderController::class, 'index'])->middleware('permission:restaurant-orders.view');
        Route::post('restaurant-orders', [RestaurantOrderController::class, 'store'])->middleware('permission:restaurant-orders.create');
        Route::get('restaurant-orders/{restaurantOrder}', [RestaurantOrderController::class, 'show'])->middleware('permission:restaurant-orders.view');
        Route::match(['put', 'patch'], 'restaurant-orders/{restaurantOrder}', [RestaurantOrderController::class, 'update'])->middleware('permission:restaurant-orders.edit');

        Route::get('kitchen-orders', [KitchenOrderTicketController::class, 'index'])->middleware('permission:kitchen-orders.view');
        Route::post('kitchen-orders/{kitchenOrderTicket}/status', [KitchenOrderTicketController::class, 'setStatus'])->middleware('permission:kitchen-orders.edit');

        /*
        |----------------------------------------------------------------------
        | Optical: eye tests and the spectacle orders ground from them. The
        | workshop flow runs forward only through transition(), because each
        | step stamps a time and delivering bills the order.
        |----------------------------------------------------------------------
        */
        Route::post('optical-orders/{opticalOrder}/transition', [OpticalOrderController::class, 'transition'])->middleware('permission:optical-orders.edit');
        $crud('optical-orders', OpticalOrderController::class, 'optical-orders', 'opticalOrder');
        $crud('eye-prescriptions', EyePrescriptionController::class, 'eye-prescriptions', 'eyePrescription');

        /*
        |----------------------------------------------------------------------
        | Jewellery: the daily rate card and old-gold exchange. Rates have no
        | update route on purpose — re-quoting the same metal, purity and day
        | replaces the row through store(), so a shop always has exactly one
        | rate per day rather than two the pricing could race on.
        |----------------------------------------------------------------------
        */
        Route::get('metal-rates/today', [MetalRateController::class, 'today'])->middleware('permission:metal-rates.view');
        Route::get('metal-rates', [MetalRateController::class, 'index'])->middleware('permission:metal-rates.view');
        Route::post('metal-rates', [MetalRateController::class, 'store'])->middleware('permission:metal-rates.create');
        Route::delete('metal-rates/{metalRate}', [MetalRateController::class, 'destroy'])->middleware('permission:metal-rates.delete');

        Route::post('old-gold/quote', [OldGoldReceiptController::class, 'quote'])->middleware('permission:old-gold.view');
        Route::get('old-gold', [OldGoldReceiptController::class, 'index'])->middleware('permission:old-gold.view');
        Route::post('old-gold', [OldGoldReceiptController::class, 'store'])->middleware('permission:old-gold.create');
        Route::get('old-gold/{oldGoldReceipt}', [OldGoldReceiptController::class, 'show'])->middleware('permission:old-gold.view');
        Route::delete('old-gold/{oldGoldReceipt}', [OldGoldReceiptController::class, 'destroy'])->middleware('permission:old-gold.delete');

        /*
        |----------------------------------------------------------------------
        | Pharmacy: prescriptions and the Schedule H1 / X register. The register
        | is read-only by design — its rows are written by dispensing at the
        | counter, never by hand, so there is no write route to reach them.
        |----------------------------------------------------------------------
        */
        Route::get('drug-register', [PrescriptionController::class, 'register'])->middleware('permission:drug-register.view');
        $crud('prescriptions', PrescriptionController::class, 'prescriptions', 'prescription');

        /*
        |----------------------------------------------------------------------
        | Project Management: budgeted work, its task board, and the hours
        | logged against it. Timesheets carry their own permission set because
        | logging your own hours is something everyone on a project does, while
        | approving them (which is what makes them billable) is not.
        |----------------------------------------------------------------------
        */
        Route::get('projects/{project}/costing', [ProjectController::class, 'costing'])->middleware('permission:projects.view');
        Route::post('projects/{project}/transition', [ProjectController::class, 'transition'])->middleware('permission:projects.edit');
        Route::post('projects/{project}/bill', [ProjectController::class, 'bill'])->middleware('permission:projects.delete');
        $crud('projects', ProjectController::class, 'projects', 'project');

        // Tasks are worked inside the project screen, so they own no menu row of
        // their own — which means EnabledModules cannot infer their module and
        // would leave them ungated. Stated explicitly here instead.
        Route::middleware('module:projects')->group(function () {
            Route::post('project-tasks/reorder', [ProjectTaskController::class, 'reorder'])->middleware('permission:project-tasks.edit');
            Route::post('project-tasks/{projectTask}/move', [ProjectTaskController::class, 'move'])->middleware('permission:project-tasks.edit');
            Route::get('project-tasks', [ProjectTaskController::class, 'index'])->middleware('permission:project-tasks.view');
            Route::post('project-tasks', [ProjectTaskController::class, 'store'])->middleware('permission:project-tasks.create');
            Route::get('project-tasks/{projectTask}', [ProjectTaskController::class, 'show'])->middleware('permission:project-tasks.view');
            Route::match(['put', 'patch'], 'project-tasks/{projectTask}', [ProjectTaskController::class, 'update'])->middleware('permission:project-tasks.edit');
            Route::delete('project-tasks/{projectTask}', [ProjectTaskController::class, 'destroy'])->middleware('permission:project-tasks.delete');
        });

        Route::post('project-timesheets/submit', [ProjectTimesheetController::class, 'submit'])->middleware('permission:project-timesheets.edit');
        Route::post('project-timesheets/approve', [ProjectTimesheetController::class, 'approve'])->middleware('permission:project-timesheets.approve');
        Route::post('project-timesheets/reject', [ProjectTimesheetController::class, 'reject'])->middleware('permission:project-timesheets.approve');
        $crud('project-timesheets', ProjectTimesheetController::class, 'project-timesheets', 'projectTimesheet');

        /*
        |----------------------------------------------------------------------
        | Textile-specific (SRS §5): fabric roll ("than") management, 4-point
        | fabric inspection, shrinkage records, and cutting (cutting-loss +
        | leftover-fabric management). All guarded by the single "textile" module.
        |----------------------------------------------------------------------
        */
        // Reports + summaries must precede the /{fabricRoll} wildcard.
        Route::get('fabric-rolls/stock-summary', [FabricRollController::class, 'stockSummary'])->middleware('permission:textile.view');
        Route::get('fabric-rolls', [FabricRollController::class, 'index'])->middleware('permission:textile.view');
        Route::post('fabric-rolls', [FabricRollController::class, 'store'])->middleware('permission:textile.create');
        Route::get('fabric-rolls/{fabricRoll}', [FabricRollController::class, 'show'])->middleware('permission:textile.view');
        Route::match(['put', 'patch'], 'fabric-rolls/{fabricRoll}', [FabricRollController::class, 'update'])->middleware('permission:textile.edit');
        Route::delete('fabric-rolls/{fabricRoll}', [FabricRollController::class, 'destroy'])->middleware('permission:textile.delete');
        Route::post('fabric-rolls/{fabricRoll}/status', [FabricRollController::class, 'setStatus'])->middleware('permission:textile.edit');

        Route::get('fabric-inspections', [FabricInspectionController::class, 'index'])->middleware('permission:textile.view');
        Route::post('fabric-inspections', [FabricInspectionController::class, 'store'])->middleware('permission:textile.create');
        Route::get('fabric-inspections/{fabricInspection}', [FabricInspectionController::class, 'show'])->middleware('permission:textile.view');
        Route::match(['put', 'patch'], 'fabric-inspections/{fabricInspection}', [FabricInspectionController::class, 'update'])->middleware('permission:textile.edit');
        Route::delete('fabric-inspections/{fabricInspection}', [FabricInspectionController::class, 'destroy'])->middleware('permission:textile.delete');

        Route::get('shrinkage-records', [ShrinkageRecordController::class, 'index'])->middleware('permission:textile.view');
        Route::post('shrinkage-records', [ShrinkageRecordController::class, 'store'])->middleware('permission:textile.create');
        Route::get('shrinkage-records/{shrinkageRecord}', [ShrinkageRecordController::class, 'show'])->middleware('permission:textile.view');
        Route::match(['put', 'patch'], 'shrinkage-records/{shrinkageRecord}', [ShrinkageRecordController::class, 'update'])->middleware('permission:textile.edit');
        Route::delete('shrinkage-records/{shrinkageRecord}', [ShrinkageRecordController::class, 'destroy'])->middleware('permission:textile.delete');

        Route::get('fabric-cuttings/loss-report', [FabricCuttingController::class, 'lossReport'])->middleware('permission:textile.view');
        Route::get('fabric-cuttings', [FabricCuttingController::class, 'index'])->middleware('permission:textile.view');
        Route::post('fabric-cuttings', [FabricCuttingController::class, 'store'])->middleware('permission:textile.create');
        Route::get('fabric-cuttings/{fabricCutting}', [FabricCuttingController::class, 'show'])->middleware('permission:textile.view');
        Route::delete('fabric-cuttings/{fabricCutting}', [FabricCuttingController::class, 'destroy'])->middleware('permission:textile.delete');

        /*
        |----------------------------------------------------------------------
        | Company Expenses: operating spend with approval workflow, bill
        | attachments and month / branch / category-wise reporting.
        |----------------------------------------------------------------------
        */
        $crud('expense-categories', ExpenseCategoryController::class, 'expense-categories', 'expenseCategory');

        // Report endpoints must precede the /{expense} wildcard.
        Route::get('expenses/report/monthly', [ExpenseController::class, 'monthlyReport'])->middleware('permission:expenses.view');
        Route::get('expenses/report/branch', [ExpenseController::class, 'branchReport'])->middleware('permission:expenses.view');
        Route::get('expenses/report/category', [ExpenseController::class, 'categoryReport'])->middleware('permission:expenses.view');
        Route::get('expenses/ledger', [ExpenseController::class, 'ledger'])->middleware('permission:expenses.view');
        Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view');
        Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.create');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view');
        Route::match(['put', 'patch'], 'expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.edit');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.delete');
        Route::post('expenses/{expense}/attachment', [ExpenseController::class, 'uploadAttachment'])->middleware('permission:expenses.edit');
        Route::post('expenses/{expense}/submit', [ExpenseController::class, 'submit'])->middleware('permission:expenses.edit');
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('permission:expenses.approve');
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:expenses.approve');
        Route::post('expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid'])->middleware('permission:expenses.edit');

        /*
        |----------------------------------------------------------------------
        | Expense sub-modules (SRS §10): employee claims, vehicle & fuel
        | expenses, electricity bills, factory & asset maintenance — each on
        | the shared approval workflow. Plus the Vehicle & Asset masters they
        | reference. Report routes precede the /{id} wildcard.
        |----------------------------------------------------------------------
        */
        $expenseWorkflow = function (string $uri, string $controller, string $module, string $param) {
            Route::post("{$uri}/{{$param}}/submit", [$controller, 'submit'])->middleware("permission:{$module}.edit");
            Route::post("{$uri}/{{$param}}/approve", [$controller, 'approve'])->middleware("permission:{$module}.approve");
            Route::post("{$uri}/{{$param}}/reject", [$controller, 'reject'])->middleware("permission:{$module}.approve");
            Route::post("{$uri}/{{$param}}/mark-paid", [$controller, 'markPaid'])->middleware("permission:{$module}.edit");
            Route::post("{$uri}/{{$param}}/attachment", [$controller, 'uploadAttachment'])->middleware("permission:{$module}.edit");
        };

        // Masters
        $crud('vehicles', VehicleController::class, 'vehicles', 'vehicle');
        $crud('assets', AssetController::class, 'assets', 'asset');

        // Employee expense claims (header + line items)
        $crud('employee-claims', EmployeeClaimController::class, 'employee-claims', 'employeeClaim');
        $expenseWorkflow('employee-claims', EmployeeClaimController::class, 'employee-claims', 'employeeClaim');

        // Vehicle expenses
        Route::get('vehicle-expenses/report', [VehicleExpenseController::class, 'report'])->middleware('permission:vehicle-expenses.view');
        $crud('vehicle-expenses', VehicleExpenseController::class, 'vehicle-expenses', 'vehicleExpense');
        $expenseWorkflow('vehicle-expenses', VehicleExpenseController::class, 'vehicle-expenses', 'vehicleExpense');

        // Fuel expenses (+ mileage report)
        Route::get('fuel-expenses/report', [FuelExpenseController::class, 'report'])->middleware('permission:fuel-expenses.view');
        $crud('fuel-expenses', FuelExpenseController::class, 'fuel-expenses', 'fuelExpense');
        $expenseWorkflow('fuel-expenses', FuelExpenseController::class, 'fuel-expenses', 'fuelExpense');

        // Electricity bills
        Route::get('electricity-bills/report', [ElectricityBillController::class, 'report'])->middleware('permission:electricity-bills.view');
        $crud('electricity-bills', ElectricityBillController::class, 'electricity-bills', 'electricityBill');
        $expenseWorkflow('electricity-bills', ElectricityBillController::class, 'electricity-bills', 'electricityBill');

        // Factory maintenance
        Route::get('factory-maintenances/report', [FactoryMaintenanceController::class, 'report'])->middleware('permission:factory-maintenances.view');
        $crud('factory-maintenances', FactoryMaintenanceController::class, 'factory-maintenances', 'factoryMaintenance');
        $expenseWorkflow('factory-maintenances', FactoryMaintenanceController::class, 'factory-maintenances', 'factoryMaintenance');

        // Asset maintenance (+ upcoming-service-due & cost report)
        Route::get('asset-maintenances/report', [AssetMaintenanceController::class, 'report'])->middleware('permission:asset-maintenances.view');
        Route::get('asset-maintenances/upcoming', [AssetMaintenanceController::class, 'upcoming'])->middleware('permission:asset-maintenances.view');
        $crud('asset-maintenances', AssetMaintenanceController::class, 'asset-maintenances', 'assetMaintenance');
        $expenseWorkflow('asset-maintenances', AssetMaintenanceController::class, 'asset-maintenances', 'assetMaintenance');

        /*
        |----------------------------------------------------------------------
        | Transport & Logistics (SRS §12): driver & delivery-route masters,
        | dispatch planning (header + consignments) with a dispatch → deliver
        | lifecycle, per-consignment Proof of Delivery, and freight / transport
        | charges. Vehicle management reuses the shared `vehicles` master.
        |----------------------------------------------------------------------
        */
        $crud('drivers', DriverController::class, 'drivers', 'driver');
        $crud('delivery-routes', DeliveryRouteController::class, 'delivery-routes', 'deliveryRoute');

        // Dispatch planning (draft → dispatched → delivered / cancelled).
        $crud('dispatch-plans', DispatchPlanController::class, 'dispatch-plans', 'dispatchPlan');
        Route::post('dispatch-plans/{dispatchPlan}/dispatch', [DispatchPlanController::class, 'dispatchPlan'])->middleware('permission:dispatch-plans.edit');
        Route::post('dispatch-plans/{dispatchPlan}/deliver', [DispatchPlanController::class, 'deliver'])->middleware('permission:dispatch-plans.edit');
        Route::post('dispatch-plans/{dispatchPlan}/cancel', [DispatchPlanController::class, 'cancel'])->middleware('permission:dispatch-plans.edit');

        // Proof of Delivery — operates on individual consignments.
        Route::get('dispatch-pods', [ProofOfDeliveryController::class, 'index'])->middleware('permission:dispatch-pods.view');
        Route::post('dispatch-pods/{dispatchItem}', [ProofOfDeliveryController::class, 'record'])->middleware('permission:dispatch-pods.edit');
        Route::post('dispatch-pods/{dispatchItem}/photo', [ProofOfDeliveryController::class, 'uploadPhoto'])->middleware('permission:dispatch-pods.edit');

        // Transport charges (freight / loading / toll / detention).
        $crud('transport-charges', TransportChargeController::class, 'transport-charges', 'transportCharge');

        /*
        |----------------------------------------------------------------------
        | Import & Export Logistics — Masters. The reference data behind every
        | cross-border shipment: countries and ports, the carriers (shipping
        | lines / airlines) and the service providers (freight forwarders, CHA,
        | inland transporters). Overseas suppliers & customers are NOT separate
        | endpoints — they are the existing party masters filtered with
        | ?is_overseas=1, so PO → import and SO → export links keep working.
        |----------------------------------------------------------------------
        */
        $crud('countries', CountryController::class, 'countries', 'country');
        $crud('ports', PortController::class, 'ports', 'port');
        $crud('shipping-lines', ShippingLineController::class, 'shipping-lines', 'shippingLine');
        $crud('airlines', AirlineController::class, 'airlines', 'airline');
        $crud('freight-forwarders', FreightForwarderController::class, 'freight-forwarders', 'freightForwarder');
        $crud('cha-agents', ChaAgentController::class, 'cha-agents', 'chaAgent');
        $crud('transporters', TransporterController::class, 'transporters', 'transporter');

        /*
        |----------------------------------------------------------------------
        | Import & Export Logistics — Shipments. The operational half of the
        | module: the import/export shipment document with its status and customs
        | tracks, its containers, trade documents, milestone timeline, logistics
        | costs and goods-value settlements, plus the read-only boards the desk
        | works from (dashboard, ETA/ETD, pending customs, pending payments,
        | shipment profit).
        |
        | Everything sits behind `module:logistics`, the same gate the exim masters
        | and the transport screens carry, so a workspace that never switched the
        | module on cannot reach it by URL either.
        |----------------------------------------------------------------------
        */
        Route::middleware('module:logistics')->group(function () use ($crud) {
            // Catalogue of document types / cost heads / milestones for the pickers.
            Route::get('import-export/catalogue', [ShipmentController::class, 'catalogue'])->middleware('permission:shipments.view');

            // The boards. Pending Documents and Container Status are the default
            // views of their own resources, so they live on those endpoints.
            Route::get('import-export/dashboard', [ImportExportController::class, 'dashboard'])->middleware('permission:shipments.view');
            Route::get('import-export/eta-etd', [ImportExportController::class, 'etaEtd'])->middleware('permission:shipments.view');
            Route::get('import-export/pending-customs', [ImportExportController::class, 'pendingCustoms'])->middleware('permission:shipments.view');
            Route::get('import-export/pending-payments', [ImportExportController::class, 'pendingPayments'])->middleware('permission:shipments.view');
            Route::get('import-export/shipment-profit', [ImportExportController::class, 'shipmentProfit'])->middleware('permission:shipments.view');

            // Shipments (draft → booked → in_transit → arrived → customs →
            // cleared → delivered → closed), with customs on its own track.
            $crud('shipments', ShipmentController::class, 'shipments', 'shipment');
            Route::post('shipments/{shipment}/status', [ShipmentController::class, 'changeStatus'])->middleware('permission:shipments.edit');
            Route::post('shipments/{shipment}/customs', [ShipmentController::class, 'customs'])->middleware('permission:shipment-customs.edit');

            /*
            |------------------------------------------------------------------
            | ERP links. The Import (PO → shipment → supplier invoice →
            | warehouse receipt → stock) and Export (SO → shipment → customer
            | invoice → dispatch) chains. Each action needs the shipment
            | permission AND the permission for the document it touches, so a
            | logistics desk cannot post stock or raise a dispatch it would not
            | otherwise be allowed to create.
            |------------------------------------------------------------------
            */
            Route::post('shipments/from-purchase-order', [ShipmentIntegrationController::class, 'fromPurchaseOrder'])
                ->middleware(['permission:shipments.create', 'permission:purchases.view']);
            Route::post('shipments/from-sales-order', [ShipmentIntegrationController::class, 'fromSalesOrder'])
                ->middleware(['permission:shipments.create', 'permission:sales.view']);
            Route::post('shipments/{shipment}/supplier-invoice', [ShipmentIntegrationController::class, 'supplierInvoice'])
                ->middleware(['permission:shipments.edit', 'permission:purchase-invoices.view']);
            Route::post('shipments/{shipment}/customer-invoice', [ShipmentIntegrationController::class, 'customerInvoice'])
                ->middleware(['permission:shipments.edit', 'permission:sales.view']);
            // The carrier's B/L or AWB: vessel, sailing, freight and the
            // container list in one act. Containers are only opened when the
            // caller may create them — the controller checks that itself, so a
            // documentation desk can still file the paper without them.
            Route::post('shipments/{shipment}/bill-of-lading', [ShipmentIntegrationController::class, 'billOfLading'])
                ->middleware('permission:shipments.edit');
            Route::post('shipments/{shipment}/warehouse-receipt', [ShipmentIntegrationController::class, 'warehouseReceipt'])
                ->middleware(['permission:shipments.edit', 'permission:purchases.create']);
            Route::post('shipments/{shipment}/dispatch', [ShipmentIntegrationController::class, 'dispatch'])
                ->middleware(['permission:shipments.edit', 'permission:dispatch-plans.create']);

            /*
            |------------------------------------------------------------------
            | Export chain. The rest of SO → Packing List → Container Booking →
            | Shipping Bill → ETD → Customer Invoice → Dispatch: the packing list
            | the cargo is declared on, the carrier booking that fixes the
            | sailing, and the shipping bill that clears it out of the country.
            |
            | The booking may plan containers, which the controller gates on the
            | container permission itself (as the B/L action does), so a
            | documentation desk can still record the booking without them.
            |------------------------------------------------------------------
            */
            Route::get('shipments/{shipment}/packing-list', [ShipmentPackingItemController::class, 'index'])
                ->middleware('permission:shipments.view');
            Route::post('shipments/{shipment}/packing-list/generate', [ShipmentIntegrationController::class, 'packingList'])
                ->middleware('permission:shipments.edit');
            Route::post('shipments/{shipment}/packing-items', [ShipmentPackingItemController::class, 'store'])
                ->middleware('permission:shipments.edit');
            Route::post('shipments/{shipment}/packing-items/assign', [ShipmentPackingItemController::class, 'assignContainer'])
                ->middleware(['permission:shipments.edit', 'permission:shipment-containers.edit']);
            Route::put('shipment-packing-items/{shipmentPackingItem}', [ShipmentPackingItemController::class, 'update'])
                ->middleware('permission:shipments.edit');
            Route::delete('shipment-packing-items/{shipmentPackingItem}', [ShipmentPackingItemController::class, 'destroy'])
                ->middleware('permission:shipments.edit');
            Route::post('shipments/{shipment}/booking', [ShipmentIntegrationController::class, 'booking'])
                ->middleware('permission:shipments.edit');
            Route::post('shipments/{shipment}/shipping-bill', [ShipmentIntegrationController::class, 'shippingBill'])
                ->middleware('permission:shipment-customs.edit');

            // Container: price the overdue free days into a detention /
            // demurrage cost on the shipment (re-runnable while it accrues).
            Route::post('shipment-containers/{shipmentContainer}/detention-charge', [ShipmentIntegrationController::class, 'detentionCharge'])
                ->middleware(['permission:shipment-containers.edit', 'permission:shipment-expenses.create']);

            // Milestone timeline.
            Route::get('shipments/{shipment}/timeline', [ShipmentController::class, 'timeline'])->middleware('permission:shipments.view');
            Route::post('shipments/{shipment}/events', [ShipmentController::class, 'addEvent'])->middleware('permission:shipments.edit');
            Route::delete('shipments/{shipment}/events/{event}', [ShipmentController::class, 'destroyEvent'])->middleware('permission:shipments.edit');

            // Containers — summary before the {shipmentContainer} routes so
            // "summary" is not swallowed as an id.
            Route::get('shipment-containers/summary', [ShipmentContainerController::class, 'summary'])->middleware('permission:shipment-containers.view');
            $crud('shipment-containers', ShipmentContainerController::class, 'shipment-containers', 'shipmentContainer');
            Route::post('shipment-containers/{shipmentContainer}/move', [ShipmentContainerController::class, 'move'])->middleware('permission:shipment-containers.edit');

            // Trade documents (+ scanned copies on the tenant's private disk).
            Route::get('shipment-documents/summary', [ShipmentDocumentController::class, 'summary'])->middleware('permission:shipment-documents.view');
            $crud('shipment-documents', ShipmentDocumentController::class, 'shipment-documents', 'shipmentDocument');
            Route::post('shipment-documents/{shipmentDocument}/file', [ShipmentDocumentController::class, 'upload'])->middleware('permission:shipment-documents.edit');
            Route::delete('shipment-documents/{shipmentDocument}/file', [ShipmentDocumentController::class, 'deleteFile'])->middleware('permission:shipment-documents.edit');

            // Logistics expenses.
            Route::get('shipment-expenses/summary', [ShipmentExpenseController::class, 'summary'])->middleware('permission:shipment-expenses.view');
            $crud('shipment-expenses', ShipmentExpenseController::class, 'shipment-expenses', 'shipmentExpense');
            Route::post('shipment-expenses/{shipmentExpense}/pay', [ShipmentExpenseController::class, 'pay'])->middleware('permission:shipment-expenses.edit');

            // Goods-value settlements (no update — a payment is corrected by
            // deleting and re-posting it, like the rest of the app's receipts).
            Route::get('shipment-payments', [ShipmentPaymentController::class, 'index'])->middleware('permission:shipment-payments.view');
            Route::post('shipment-payments', [ShipmentPaymentController::class, 'store'])->middleware('permission:shipment-payments.create');
            Route::delete('shipment-payments/{shipmentPayment}', [ShipmentPaymentController::class, 'destroy'])->middleware('permission:shipment-payments.delete');
        });

        /*
        |----------------------------------------------------------------------
        | Accounts: cash/bank account master, manual vouchers (receipt /
        | payment / contra / journal) and the derived account books
        | (Cash Book, Bank Book, Journal, Day Book).
        |----------------------------------------------------------------------
        */
        $crud('bank-accounts', BankAccountController::class, 'bank-accounts', 'bankAccount');

        // Read-only derived books.
        Route::get('accounts/cash-book', [AccountBookController::class, 'cashBook'])->middleware('permission:accounts.view');
        Route::get('accounts/bank-book', [AccountBookController::class, 'bankBook'])->middleware('permission:accounts.view');
        Route::get('accounts/day-book', [AccountBookController::class, 'dayBook'])->middleware('permission:accounts.view');
        Route::get('accounts/journal', [AccountBookController::class, 'journal'])->middleware('permission:accounts.view');
        Route::get('accounts/petty-cash', [AccountBookController::class, 'pettyCash'])->middleware('permission:accounts.view');

        // Derived financial statements (read-only). These are the menu's
        // "Financial Reports" group, so they carry its module tag as well.
        Route::get('accounts/trial-balance', [FinancialStatementController::class, 'trialBalance'])->middleware(['permission:accounts.view', 'module:accounts']);
        Route::get('accounts/profit-loss', [FinancialStatementController::class, 'profitAndLoss'])->middleware(['permission:accounts.view', 'module:accounts']);
        Route::get('accounts/balance-sheet', [FinancialStatementController::class, 'balanceSheet'])->middleware(['permission:accounts.view', 'module:accounts']);

        // TDS / TCS section-rate master.
        $crud('tds-tcs-rates', TdsTcsRateController::class, 'tds-tcs-rates', 'tdsTcsRate');

        // Manual vouchers.
        Route::get('vouchers', [VoucherController::class, 'index'])->middleware('permission:vouchers.view');
        Route::post('vouchers', [VoucherController::class, 'store'])->middleware('permission:vouchers.create');
        Route::get('vouchers/{voucher}', [VoucherController::class, 'show'])->middleware('permission:vouchers.view');
        Route::match(['put', 'patch'], 'vouchers/{voucher}', [VoucherController::class, 'update'])->middleware('permission:vouchers.edit');
        Route::delete('vouchers/{voucher}', [VoucherController::class, 'destroy'])->middleware('permission:vouchers.delete');
        Route::post('vouchers/{voucher}/cancel', [VoucherController::class, 'cancel'])->middleware('permission:vouchers.edit');

        /*
        |----------------------------------------------------------------------
        | Advanced inventory
        |----------------------------------------------------------------------
        */
        Route::get('batches', [BatchController::class, 'index'])->middleware('permission:advanced-inventory.view');
        Route::get('serial-numbers', [SerialNumberController::class, 'index'])->middleware('permission:advanced-inventory.view');

        Route::get('inventory-alerts', [InventoryAlertController::class, 'index'])->middleware('permission:advanced-inventory.view');
        Route::post('inventory-alerts/generate', [InventoryAlertController::class, 'generate'])->middleware('permission:advanced-inventory.edit');
        Route::post('inventory-alerts/{inventoryAlert}/acknowledge', [InventoryAlertController::class, 'acknowledge'])->middleware('permission:advanced-inventory.edit');
        Route::post('inventory-alerts/{inventoryAlert}/resolve', [InventoryAlertController::class, 'resolve'])->middleware('permission:advanced-inventory.edit');
        Route::delete('inventory-alerts/{inventoryAlert}', [InventoryAlertController::class, 'destroy'])->middleware('permission:advanced-inventory.delete');

        /*
        |----------------------------------------------------------------------
        | Reports
        |----------------------------------------------------------------------
        | Every report carries `module:<key>` alongside its permission so a tenant
        | can only pull data from the modules it switched on in onboarding — the
        | same tags MenuSeeder puts on the Reports submenu, so the sidebar and the
        | API can never disagree. Keys must stay in sync with those tags
        | (App\Support\Modules holds the catalogue).
        */
        Route::get('reports/dead-stock', [InventoryReportController::class, 'deadStock'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/current-stock', [InventoryReportController::class, 'currentStock'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/low-stock', [InventoryReportController::class, 'lowStock'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/negative-stock', [InventoryReportController::class, 'negativeStock'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/expiry-stock', [InventoryReportController::class, 'expiryStock'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/stock-movement', [InventoryReportController::class, 'stockMovementSummary'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/fast-slow-moving', [InventoryReportController::class, 'fastSlowMoving'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/valuation', [InventoryReportController::class, 'valuation'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/purchases', [PurchaseReportController::class, 'summary'])->middleware(['permission:reports.view', 'module:purchase']);
        Route::get('reports/vendor-performance', [PurchaseReportController::class, 'vendorPerformance'])->middleware(['permission:reports.view', 'module:purchase']);
        // The GST screen toggles between input (purchase) and output (sales) tax,
        // so either the GST module or the side it reads keeps each half reachable.
        Route::get('reports/gst-input', [PurchaseReportController::class, 'gstInput'])->middleware(['permission:reports.view', 'module:gst,purchase']);
        Route::get('reports/payables', [PurchaseReportController::class, 'payables'])->middleware(['permission:reports.view', 'module:purchase']);
        Route::get('reports/sales', [SalesReportController::class, 'summary'])->middleware(['permission:reports.view', 'module:sales']);
        Route::get('reports/profitability', [SalesReportController::class, 'profitability'])->middleware(['permission:reports.view', 'module:sales']);
        Route::get('reports/gst-output', [SalesReportController::class, 'gstOutput'])->middleware(['permission:reports.view', 'module:gst,sales']);
        // Consolidated GST (GSTR-3B summary + GSTR-1) and TDS/TCS registers.
        Route::get('reports/gst-summary', [GstReportController::class, 'summary'])->middleware(['permission:reports.view', 'module:gst']);
        Route::get('reports/gstr1', [GstReportController::class, 'gstr1'])->middleware(['permission:reports.view', 'module:gst']);
        Route::get('reports/gstr2', [GstReportController::class, 'gstr2'])->middleware(['permission:reports.view', 'module:gst']);
        // Government offline-tool JSON exports (GالسTN upload schema).
        Route::get('reports/gstr1/json', [GstReportController::class, 'gstr1Json'])->middleware(['permission:reports.view', 'module:gst']);
        Route::get('reports/gstr3b/json', [GstReportController::class, 'gstr3bJson'])->middleware(['permission:reports.view', 'module:gst']);
        Route::get('reports/tds-register', [TaxRegisterController::class, 'tds'])->middleware(['permission:reports.view', 'module:gst']);
        Route::get('reports/tcs-register', [TaxRegisterController::class, 'tcs'])->middleware(['permission:reports.view', 'module:gst']);
        Route::get('reports/broker-commissions', [BrokerReportController::class, 'commissions'])->middleware(['permission:reports.view', 'module:broker']);
        Route::get('reports/broker-wise-sales', [BrokerReportController::class, 'brokerWiseSales'])->middleware(['permission:reports.view', 'module:broker']);
        Route::get('reports/stock-ageing', [InventoryReportController::class, 'stockAgeing'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/slow-moving', [InventoryReportController::class, 'slowMoving'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/fast-moving', [InventoryReportController::class, 'fastMoving'])->middleware(['permission:reports.view', 'module:inventory']);
        Route::get('reports/manufacturing', [ManufacturingReportController::class, 'summary'])->middleware(['permission:reports.view', 'module:manufacturing']);
        Route::get('reports/production-cost', [ManufacturingReportController::class, 'productionCost'])->middleware(['permission:reports.view', 'module:manufacturing']);
        Route::get('reports/tailor', [JobWorkReportController::class, 'tailor'])->middleware(['permission:reports.view', 'module:tailoring']);
        Route::get('reports/tailor-performance', [TailorPerformanceController::class, 'index'])->middleware(['permission:reports.view', 'module:tailoring']);
        Route::get('reports/tailor-rejections', [TailorPerformanceController::class, 'rejections'])->middleware(['permission:reports.view', 'module:tailoring']);
        Route::get('reports/embroidery', [JobWorkReportController::class, 'embroidery'])->middleware(['permission:reports.view', 'module:embroidery']);
        Route::get('reports/printing', [JobWorkReportController::class, 'printing'])->middleware(['permission:reports.view', 'module:manufacturing']);
        Route::get('reports/expenses', [ExpenseController::class, 'report'])->middleware(['permission:reports.view', 'module:accounts']);
        Route::get('reports/customer-ledger', [LedgerReportController::class, 'customer'])->middleware(['permission:reports.view', 'module:accounts']);
        Route::get('reports/supplier-ledger', [LedgerReportController::class, 'supplier'])->middleware(['permission:reports.view', 'module:accounts']);

        // Import & Export Logistics reports (SRS §24). These are registers over a
        // date range that leave as CSV / Excel / PDF, as opposed to the module's
        // own boards (/import-export/*), which are the work queues the desk acts
        // on. Both read the same shipments and the same money rules.
        Route::get('reports/import-shipments', [ShipmentReportController::class, 'importReport'])->middleware(['permission:reports.view', 'module:logistics']);
        Route::get('reports/export-shipments', [ShipmentReportController::class, 'exportReport'])->middleware(['permission:reports.view', 'module:logistics']);
        Route::get('reports/shipment-profit', [ShipmentReportController::class, 'shipmentProfit'])->middleware(['permission:reports.view', 'module:logistics']);
        Route::get('reports/shipment-expenses', [ShipmentReportController::class, 'shipmentExpense'])->middleware(['permission:reports.view', 'module:logistics']);
        Route::get('reports/shipment-customs', [ShipmentReportController::class, 'customsReport'])->middleware(['permission:reports.view', 'module:logistics']);
        Route::get('reports/shipment-containers', [ShipmentReportController::class, 'containerReport'])->middleware(['permission:reports.view', 'module:logistics']);
        Route::get('reports/country-wise-sales', [ShipmentReportController::class, 'countryWiseSales'])->middleware(['permission:reports.view', 'module:logistics']);
        Route::get('reports/port-wise-shipments', [ShipmentReportController::class, 'portWiseShipments'])->middleware(['permission:reports.view', 'module:logistics']);

        Route::get('dashboard/kpis', [DashboardController::class, 'kpis']);
        // Customizable widget layout (project-stack.md §5), per user.
        Route::get('dashboard/layout', [DashboardController::class, 'layout']);
        Route::put('dashboard/layout', [DashboardController::class, 'saveLayout']);

        Route::get('reorder/suggestions', [ReorderController::class, 'suggestions'])->middleware('permission:advanced-inventory.view');
        Route::post('reorder/generate', [ReorderController::class, 'generate'])->middleware('permission:advanced-inventory.create');

        // Gate entries
        Route::get('gate-entries', [GateEntryController::class, 'index'])->middleware('permission:gate-entries.view');
        Route::post('gate-entries', [GateEntryController::class, 'store'])->middleware('permission:gate-entries.create');
        Route::get('gate-entries/{gateEntry}', [GateEntryController::class, 'show'])->middleware('permission:gate-entries.view');
        Route::match(['put', 'patch'], 'gate-entries/{gateEntry}', [GateEntryController::class, 'update'])->middleware('permission:gate-entries.edit');
        Route::delete('gate-entries/{gateEntry}', [GateEntryController::class, 'destroy'])->middleware('permission:gate-entries.delete');
        Route::post('gate-entries/{gateEntry}/close', [GateEntryController::class, 'close'])->middleware('permission:gate-entries.edit');

        /*
        |----------------------------------------------------------------------
        | Notifications & Alerts
        |----------------------------------------------------------------------
        */
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/check-due-invoices', [NotificationController::class, 'checkDueInvoices'])->middleware('permission:notifications.view');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);

        /*
        |----------------------------------------------------------------------
        | Barcode system
        |----------------------------------------------------------------------
        */
        Route::get('barcode/render', [BarcodeController::class, 'render'])->middleware('permission:barcodes.view');
        Route::get('barcode/lookup', [BarcodeController::class, 'lookup'])->middleware('permission:barcodes.view');
        Route::post('barcode/print-labels', [BarcodeController::class, 'printLabels'])->middleware('permission:barcodes.view');
        Route::post('rfid/assign', [BarcodeController::class, 'assignRfid'])->middleware('permission:products.edit');
        Route::get('products/{product}/barcode-image', [BarcodeController::class, 'productImage'])->middleware('permission:products.view');
        Route::get('products/{product}/variants/{variant}/barcode-image', [BarcodeController::class, 'variantImage'])->middleware('permission:products.view');

        /*
        |----------------------------------------------------------------------
        | Printer testing — sample prints of the shipping templates. Nothing is
        | persisted; the thermal receipt is driven by the terminal itself.
        |----------------------------------------------------------------------
        */
        Route::post('printer-test/a4-invoice', [PrinterTestController::class, 'a4Invoice'])->middleware('permission:settings.view');
        Route::post('printer-test/labels', [PrinterTestController::class, 'labels'])->middleware('permission:settings.view');

        /*
        |----------------------------------------------------------------------
        | Human Resources: departments, employees, attendance, salary
        |----------------------------------------------------------------------
        */
        $crud('departments', DepartmentController::class, 'departments', 'department');
        $crud('employees', EmployeeController::class, 'employees', 'employee');
        $crud('attendance', AttendanceController::class, 'attendance', 'attendance');
        $crud('salaries', SalaryController::class, 'salaries', 'salary');

        // Leave: types master + applications with an approve/reject workflow.
        $crud('leave-types', LeaveTypeController::class, 'leave-types', 'leaveType');
        Route::post('leave-applications/{leaveApplication}/approve', [LeaveApplicationController::class, 'approve'])->middleware('permission:leave-applications.approve');
        Route::post('leave-applications/{leaveApplication}/reject', [LeaveApplicationController::class, 'reject'])->middleware('permission:leave-applications.approve');
        $crud('leave-applications', LeaveApplicationController::class, 'leave-applications', 'leaveApplication');

        // Overtime, bonuses and salary advances — each approved before payroll consumes it.
        Route::post('overtime/{overtimeRecord}/approve', [OvertimeRecordController::class, 'approve'])->middleware('permission:overtime.approve');
        $crud('overtime', OvertimeRecordController::class, 'overtime', 'overtimeRecord');

        Route::post('employee-bonuses/{employeeBonus}/approve', [EmployeeBonusController::class, 'approve'])->middleware('permission:employee-bonuses.approve');
        $crud('employee-bonuses', EmployeeBonusController::class, 'employee-bonuses', 'employeeBonus');

        Route::post('salary-advances/{salaryAdvance}/approve', [SalaryAdvanceController::class, 'approve'])->middleware('permission:salary-advances.approve');
        $crud('salary-advances', SalaryAdvanceController::class, 'salary-advances', 'salaryAdvance');

        // Employee loans: approve → disburse (builds the EMI schedule) → recovered via payroll.
        Route::post('employee-loans/{employeeLoan}/approve', [EmployeeLoanController::class, 'approve'])->middleware('permission:employee-loans.approve');
        Route::post('employee-loans/{employeeLoan}/disburse', [EmployeeLoanController::class, 'disburse'])->middleware('permission:employee-loans.edit');
        $crud('employee-loans', EmployeeLoanController::class, 'employee-loans', 'employeeLoan');

        // Biometric attendance: device master + punch ingest/sync into attendance.
        $crud('biometric-devices', BiometricDeviceController::class, 'biometric-devices', 'biometricDevice');
        Route::get('biometric-punches', [BiometricPunchController::class, 'index'])->middleware('permission:biometric-punches.view');
        Route::post('biometric-punches/import', [BiometricPunchController::class, 'import'])->middleware('permission:biometric-punches.create');
        Route::post('biometric-punches/punch', [BiometricPunchController::class, 'punch'])->middleware('permission:biometric-punches.create');
        Route::post('biometric-punches/sync', [BiometricPunchController::class, 'sync'])->middleware('permission:attendance.create');
        Route::delete('biometric-punches/{biometricPunch}', [BiometricPunchController::class, 'destroy'])->middleware('permission:biometric-punches.delete');

        // -------------------------------------------------------------
        // Device Manager (plugin-based hardware integration)
        //
        // The catalogue drives the configuration screen; `test` verifies a
        // connection; `simulate` fires a mock scan / print preview / fake weight
        // / virtual tag or fingerprint event; `run/{action}` performs the real
        // operation. Simulated and live runs both need write access, since both
        // can create records (punches) and consume consumables (paper).
        // -------------------------------------------------------------
        Route::get('devices/catalogue', [DeviceController::class, 'catalogue'])->middleware('permission:devices.view');
        Route::get('device-events', [DeviceEventController::class, 'index'])->middleware('permission:device-events.view');
        Route::delete('device-events', [DeviceEventController::class, 'destroy'])->middleware('permission:devices.delete');
        Route::post('devices/{device}/test', [DeviceController::class, 'test'])->middleware('permission:devices.edit');
        Route::post('devices/{device}/simulate', [DeviceController::class, 'simulate'])->middleware('permission:devices.edit');
        Route::post('devices/{device}/run/{action}', [DeviceController::class, 'run'])->middleware('permission:devices.edit');
        $crud('devices', DeviceController::class, 'devices', 'device');

        // Payroll: per-employee CTC structures + monthly payslip generation/payout.
        $crud('salary-structures', SalaryStructureController::class, 'salary-structures', 'salaryStructure');
        Route::get('payroll', [PayrollController::class, 'index'])->middleware('permission:payroll.view');
        Route::post('payroll/generate', [PayrollController::class, 'generate'])->middleware('permission:payroll.create');
        Route::post('payroll/pay-all', [PayrollController::class, 'payAll'])->middleware('permission:payroll.edit');
        Route::post('payroll/{salary}/pay', [PayrollController::class, 'pay'])->middleware('permission:payroll.edit');
        Route::delete('payroll/{salary}', [PayrollController::class, 'destroy'])->middleware('permission:payroll.delete');

        /*
        |----------------------------------------------------------------------
        | System administration
        |----------------------------------------------------------------------
        */
        Route::get('settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
        Route::match(['put', 'patch'], 'settings', [SettingController::class, 'update'])->middleware('permission:settings.edit');

        // System Tools → Import Data (project-stack.md §8): Excel / CSV / JSON.
        // Import is the only System Tool a tenant gets. The Demo Data generator
        // (§7) and Performance diagnostics (§17) are operator tools and live on
        // the platform Ops panel (/api/central/ops/…), scoped to a chosen tenant.
        Route::get('system-tools/import/modules', [DataImportController::class, 'modules'])->middleware('permission:settings.view');
        Route::get('system-tools/import/{module}/template', [DataImportController::class, 'template'])->middleware('permission:settings.view');
        Route::post('system-tools/import/{module}', [DataImportController::class, 'store'])->middleware('permission:settings.edit');
        // Poll a queued (large-file) import by its job id — §17 Performance (Queue).
        Route::get('system-tools/import/status/{jobId}', [DataImportController::class, 'status'])->middleware('permission:settings.view');

        // Workflow Builder (project-stack.md §11).
        Route::get('workflows/meta', [WorkflowController::class, 'meta'])->middleware('permission:workflows.view');
        Route::post('workflows/test', [WorkflowController::class, 'test'])->middleware('permission:workflows.view');
        Route::get('workflows', [WorkflowController::class, 'index'])->middleware('permission:workflows.view');
        Route::post('workflows', [WorkflowController::class, 'store'])->middleware('permission:workflows.create');
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])->middleware('permission:workflows.view');
        Route::match(['put', 'patch'], 'workflows/{workflow}', [WorkflowController::class, 'update'])->middleware('permission:workflows.edit');
        Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])->middleware('permission:workflows.delete');

        $crud('users', UserController::class, 'users', 'user', 'users');

        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        // Copying a system role into the workspace creates a role, so it rides on
        // roles.create rather than roles.edit (system roles are never editable).
        Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate'])->middleware('permission:roles.create');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
        Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

        /*
        |----------------------------------------------------------------------
        | Multi-company / multi-branch directory + active-context switcher
        | (intra-tenant sub-organizations)
        |----------------------------------------------------------------------
        */
        $crud('companies', CompanyController::class, 'companies', 'company');
        $crud('branches', BranchController::class, 'branches', 'branch');

        // Financial years: accounting periods per company + open/close + current-year switch
        Route::post('financial-years/{financialYear}/close', [FinancialYearController::class, 'close'])->middleware('permission:financial-years.edit');
        Route::post('financial-years/{financialYear}/reopen', [FinancialYearController::class, 'reopen'])->middleware('permission:financial-years.edit');
        Route::post('financial-years/{financialYear}/set-current', [FinancialYearController::class, 'setCurrent'])->middleware('permission:financial-years.edit');
        $crud('financial-years', FinancialYearController::class, 'financial-years', 'financialYear');

        // GST registrations: multiple state-wise GSTINs per company/branch
        $crud('gst-registrations', GstRegistrationController::class, 'gst-registrations', 'gstRegistration');

        /*
        |----------------------------------------------------------------------
        | GST Integration (SRS §20): GSTIN validation, HSN master, e-invoice
        | (IRN) and e-way bill generation.
        |----------------------------------------------------------------------
        */
        // GSTIN structural + check-digit validation and HSN type-ahead lookup.
        Route::post('gst/validate-gstin', [GstController::class, 'validateGstin']);
        Route::get('gst/hsn-lookup', [GstController::class, 'hsnLookup']);

        // HSN / SAC master.
        $crud('hsn-codes', HsnCodeController::class, 'hsn-codes', 'hsnCode');

        // E-invoice (IRN) — keyed by the underlying sales invoice.
        Route::get('e-invoices', [EInvoiceController::class, 'index'])->middleware('permission:e-invoices.view');
        Route::get('e-invoices/{invoice}', [EInvoiceController::class, 'show'])->middleware('permission:e-invoices.view');
        Route::get('e-invoices/{invoice}/preview', [EInvoiceController::class, 'preview'])->middleware('permission:e-invoices.view');
        Route::post('e-invoices/{invoice}/generate', [EInvoiceController::class, 'generate'])->middleware('permission:e-invoices.create');
        Route::post('e-invoices/{invoice}/cancel', [EInvoiceController::class, 'cancel'])->middleware('permission:e-invoices.edit');

        // E-way bill — keyed by the underlying sales invoice.
        Route::get('e-way-bills', [EWayBillController::class, 'index'])->middleware('permission:e-way-bills.view');
        Route::get('e-way-bills/{invoice}', [EWayBillController::class, 'show'])->middleware('permission:e-way-bills.view');
        Route::post('e-way-bills/{invoice}/generate', [EWayBillController::class, 'generate'])->middleware('permission:e-way-bills.create');
        Route::post('e-way-bills/{invoice}/update-vehicle', [EWayBillController::class, 'updateVehicle'])->middleware('permission:e-way-bills.edit');
        Route::post('e-way-bills/{invoice}/cancel', [EWayBillController::class, 'cancel'])->middleware('permission:e-way-bills.edit');

        /*
        |----------------------------------------------------------------------
        | AI Features (SRS §22): statistical sales forecast + demand prediction,
        | forecast-driven auto purchase suggestions, low-stock (days-to-stockout)
        | prediction, narrated AI reports, and the chat / Gujarati-voice ERP
        | assistant. All engines run locally; an optional hosted-model seam
        | (Settings → AI) only polishes narrative text.
        |----------------------------------------------------------------------
        */
        Route::get('ai/status', [AiController::class, 'status'])->middleware('permission:ai.view');
        Route::get('ai/sales-forecast', [AiController::class, 'salesForecast'])->middleware('permission:ai.view');
        Route::get('ai/demand-prediction', [AiController::class, 'demandPrediction'])->middleware('permission:ai.view');
        Route::get('ai/purchase-suggestions', [AiController::class, 'purchaseSuggestions'])->middleware('permission:ai.view');
        Route::get('ai/low-stock-prediction', [AiController::class, 'lowStockPrediction'])->middleware('permission:ai.view');
        Route::get('ai/report', [AiController::class, 'report'])->middleware('permission:ai.view');
        Route::post('ai/search', [AiController::class, 'search'])->middleware('permission:ai.view');

        Route::post('context/switch', [ContextController::class, 'switch']);

        /*
        |----------------------------------------------------------------------
        | Data backup & restore — operator-only, see /api/central/ops/backups.
        | Tenants have no backup surface: archives cover the shared database, so
        | running or downloading one is a platform concern.
        |----------------------------------------------------------------------
        */

        /*
        |----------------------------------------------------------------------
        | Audit logs (read-only)
        |----------------------------------------------------------------------
        */
        Route::get('audit-logs-meta', [AuditLogController::class, 'meta'])->middleware('permission:audit-logs.view');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit-logs.view');
        Route::get('audit-logs/{activity}', [AuditLogController::class, 'show'])->middleware('permission:audit-logs.view');

        /*
        |----------------------------------------------------------------------
        | SaaS: onboarding wizard + this tenant's subscription/billing.
        | Subscription rows live in the central DB; controllers resolve them
        | via the current tenant id.
        |----------------------------------------------------------------------
        */
        Route::get('onboarding', [OnboardingController::class, 'show']);
        Route::post('onboarding/complete-step', [OnboardingController::class, 'completeStep']);
        Route::post('onboarding/logo', [OnboardingController::class, 'uploadLogo']);
        Route::post('onboarding/invite', [OnboardingController::class, 'invite'])->middleware('permission:users.create');

        Route::get('subscription', [SubscriptionController::class, 'show']);
        Route::get('subscription/plans', [SubscriptionController::class, 'plans']);
        Route::get('subscription/invoices', [SubscriptionController::class, 'invoices']);
        Route::get('subscription/invoices/{invoice}/pdf', [SubscriptionController::class, 'invoicePdf']);
        Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan']);
        Route::post('subscription/confirm-payment', [SubscriptionController::class, 'confirmPayment']);
        Route::post('subscription/pay-now', [SubscriptionController::class, 'payNow']);
    });
});
