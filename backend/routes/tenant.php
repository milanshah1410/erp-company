<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BarcodeController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrokerController;
use App\Http\Controllers\Api\BrokerReportController;
use App\Http\Controllers\Api\BrokerSettlementController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContextController;
use App\Http\Controllers\Api\CustomerAddressController;
use App\Http\Controllers\Api\CustomerCategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerPriceListController;
use App\Http\Controllers\Api\CycleCountController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FinancialYearController;
use App\Http\Controllers\Api\GateEntryController;
use App\Http\Controllers\Api\GstRegistrationController;
use App\Http\Controllers\Api\GoodsReceiptController;
use App\Http\Controllers\Api\InventoryAlertController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseReportController;
use App\Http\Controllers\Api\ReorderController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\SalesQuotationController;
use App\Http\Controllers\Api\SalesReportController;
use App\Http\Controllers\Api\SalesReturnController;
use App\Http\Controllers\Api\SerialNumberController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockLedgerController;
use App\Http\Controllers\Api\StockReturnController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseLocationController;
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

    Route::middleware(['auth:sanctum', 'resolve.tenant', 'tenant.active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

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
        $crud('customer-categories', CustomerCategoryController::class, 'customer-categories', 'customerCategory');
        $crud('customers', CustomerController::class, 'customers', 'customer');
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
        $crud('price-lists', PriceListController::class, 'price-lists', 'priceList');

        // Brokers (Dalal): master + commission ledger + settlements
        $crud('brokers', BrokerController::class, 'brokers', 'broker');
        Route::get('brokers/{broker}/ledger', [BrokerController::class, 'ledger'])->middleware('permission:brokers.view');
        Route::get('brokers/{broker}/settlements', [BrokerController::class, 'settlements'])->middleware('permission:brokers.view');
        Route::post('brokers/{broker}/settlements', [BrokerSettlementController::class, 'store'])->middleware('permission:brokers.create');

        /*
        |----------------------------------------------------------------------
        | Purchase module
        |----------------------------------------------------------------------
        */
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchases.view');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchases.create');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchases.view');
        Route::match(['put', 'patch'], 'purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchases.edit');
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchases.delete');
        Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchases.edit');
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchases.approve');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchases.edit');

        // Goods Receipt Notes (posting stock is a create action against the purchase module)
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchases.view');
        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:purchases.create');
        Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchases.view');

        // Vendor invoices: 3-way match against PO + GRN, soft warning on exception
        Route::get('purchase-invoices', [PurchaseInvoiceController::class, 'index'])->middleware('permission:purchase-invoices.view');
        Route::post('purchase-invoices', [PurchaseInvoiceController::class, 'store'])->middleware('permission:purchase-invoices.create');
        Route::get('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'show'])->middleware('permission:purchase-invoices.view');
        Route::match(['put', 'patch'], 'purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'update'])->middleware('permission:purchase-invoices.edit');
        Route::delete('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'destroy'])->middleware('permission:purchase-invoices.delete');
        Route::post('purchase-invoices/{purchaseInvoice}/approve', [PurchaseInvoiceController::class, 'approve'])->middleware('permission:purchase-invoices.approve');
        Route::post('purchase-invoices/{purchaseInvoice}/cancel', [PurchaseInvoiceController::class, 'cancel'])->middleware('permission:purchase-invoices.edit');

        /*
        |----------------------------------------------------------------------
        | Sales module
        |----------------------------------------------------------------------
        */
        Route::get('sales-orders', [SalesOrderController::class, 'index'])->middleware('permission:sales.view');
        Route::post('sales-orders', [SalesOrderController::class, 'store'])->middleware('permission:sales.create');
        Route::get('sales-orders/{salesOrder}', [SalesOrderController::class, 'show'])->middleware('permission:sales.view');
        Route::match(['put', 'patch'], 'sales-orders/{salesOrder}', [SalesOrderController::class, 'update'])->middleware('permission:sales.edit');
        Route::delete('sales-orders/{salesOrder}', [SalesOrderController::class, 'destroy'])->middleware('permission:sales.delete');
        Route::post('sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:sales.approve');
        Route::post('sales-orders/{salesOrder}/recheck', [SalesOrderController::class, 'recheck'])->middleware('permission:sales.approve');
        Route::post('sales-orders/{salesOrder}/pick', [SalesOrderController::class, 'pick'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/pack', [SalesOrderController::class, 'pack'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/ship', [SalesOrderController::class, 'ship'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/deliver', [SalesOrderController::class, 'deliver'])->middleware('permission:sales.edit');
        Route::post('sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales.edit');

        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:sales.view');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:sales.view');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->middleware('permission:sales.create');

        // Sales quotations: pre-order stage, converts into a Sales Order once accepted
        Route::get('sales-quotations', [SalesQuotationController::class, 'index'])->middleware('permission:sales.view');
        Route::post('sales-quotations', [SalesQuotationController::class, 'store'])->middleware('permission:sales.create');
        Route::get('sales-quotations/{salesQuotation}', [SalesQuotationController::class, 'show'])->middleware('permission:sales.view');
        Route::match(['put', 'patch'], 'sales-quotations/{salesQuotation}', [SalesQuotationController::class, 'update'])->middleware('permission:sales.edit');
        Route::delete('sales-quotations/{salesQuotation}', [SalesQuotationController::class, 'destroy'])->middleware('permission:sales.delete');
        Route::post('sales-quotations/{salesQuotation}/send', [SalesQuotationController::class, 'send'])->middleware('permission:sales.edit');
        Route::post('sales-quotations/{salesQuotation}/accept', [SalesQuotationController::class, 'accept'])->middleware('permission:sales.edit');
        Route::post('sales-quotations/{salesQuotation}/reject', [SalesQuotationController::class, 'reject'])->middleware('permission:sales.edit');
        Route::post('sales-quotations/{salesQuotation}/convert', [SalesQuotationController::class, 'convert'])->middleware('permission:sales.create');

        // POS: one-shot retail counter checkout (creates SO + Invoice + Payments atomically)
        Route::get('pos/products', [PosController::class, 'products'])->middleware('permission:sales.view');
        Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware('permission:sales.create');

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

        Route::get('reports/dead-stock', [InventoryReportController::class, 'deadStock'])->middleware('permission:reports.view');
        Route::get('reports/current-stock', [InventoryReportController::class, 'currentStock'])->middleware('permission:reports.view');
        Route::get('reports/low-stock', [InventoryReportController::class, 'lowStock'])->middleware('permission:reports.view');
        Route::get('reports/negative-stock', [InventoryReportController::class, 'negativeStock'])->middleware('permission:reports.view');
        Route::get('reports/expiry-stock', [InventoryReportController::class, 'expiryStock'])->middleware('permission:reports.view');
        Route::get('reports/stock-movement', [InventoryReportController::class, 'stockMovementSummary'])->middleware('permission:reports.view');
        Route::get('reports/fast-slow-moving', [InventoryReportController::class, 'fastSlowMoving'])->middleware('permission:reports.view');
        Route::get('reports/valuation', [InventoryReportController::class, 'valuation'])->middleware('permission:reports.view');
        Route::get('reports/purchases', [PurchaseReportController::class, 'summary'])->middleware('permission:reports.view');
        Route::get('reports/vendor-performance', [PurchaseReportController::class, 'vendorPerformance'])->middleware('permission:reports.view');
        Route::get('reports/gst-input', [PurchaseReportController::class, 'gstInput'])->middleware('permission:reports.view');
        Route::get('reports/sales', [SalesReportController::class, 'summary'])->middleware('permission:reports.view');
        Route::get('reports/profitability', [SalesReportController::class, 'profitability'])->middleware('permission:reports.view');
        Route::get('reports/gst-output', [SalesReportController::class, 'gstOutput'])->middleware('permission:reports.view');
        Route::get('reports/broker-commissions', [BrokerReportController::class, 'commissions'])->middleware('permission:reports.view');
        Route::get('reports/broker-wise-sales', [BrokerReportController::class, 'brokerWiseSales'])->middleware('permission:reports.view');

        Route::get('dashboard/kpis', [DashboardController::class, 'kpis']);

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
        Route::get('products/{product}/barcode-image', [BarcodeController::class, 'productImage'])->middleware('permission:products.view');
        Route::get('products/{product}/variants/{variant}/barcode-image', [BarcodeController::class, 'variantImage'])->middleware('permission:products.view');

        /*
        |----------------------------------------------------------------------
        | Human Resources: departments, employees, attendance, salary
        |----------------------------------------------------------------------
        */
        $crud('departments', DepartmentController::class, 'departments', 'department');
        $crud('employees', EmployeeController::class, 'employees', 'employee');
        $crud('attendance', AttendanceController::class, 'attendance', 'attendance');
        $crud('salaries', SalaryController::class, 'salaries', 'salary');

        /*
        |----------------------------------------------------------------------
        | System administration
        |----------------------------------------------------------------------
        */
        Route::get('settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
        Route::match(['put', 'patch'], 'settings', [SettingController::class, 'update'])->middleware('permission:settings.edit');

        $crud('users', UserController::class, 'users', 'user', 'users');

        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
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

        Route::post('context/switch', [ContextController::class, 'switch']);

        /*
        |----------------------------------------------------------------------
        | Data backup & restore
        |----------------------------------------------------------------------
        */
        Route::get('backups', [BackupController::class, 'index'])->middleware('permission:backups.view');
        Route::post('backups/run', [BackupController::class, 'run'])->middleware('permission:backups.create');
        Route::get('backups/{filename}/download', [BackupController::class, 'download'])->middleware('permission:backups.view');
        Route::delete('backups/{filename}', [BackupController::class, 'destroy'])->middleware('permission:backups.delete');

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
        Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan']);
    });
});
