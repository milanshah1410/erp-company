import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { usePortalAuthStore } from '../stores/portalAuth'
import { useCentralAuthStore } from '../stores/centralAuth'
import { useRouteProgress } from '../composables/useRouteProgress'
import AppLayout from '../layouts/AppLayout.vue'
import PortalLayout from '../layouts/PortalLayout.vue'
import CentralAdminLayout from '../layouts/CentralAdminLayout.vue'
import LoginView from '../views/LoginView.vue'

// Route-level code splitting (§17 Performance — Lazy Loading).
//
// Only the app shell (layouts + the primary staff LoginView, the most common
// entry point) is bundled into the initial chunk. Every feature view below is a
// dynamic `() => import()` so Vite emits it as a separate chunk that is fetched
// on first navigation and cached thereafter — turning one ~130-view monolith
// bundle into ~130 small on-demand chunks. Route definitions are unchanged; they
// reference these lazy factories exactly as before.
const CentralLoginView = () => import('../views/central/CentralLoginView.vue')
const CentralDashboardView = () => import('../views/central/CentralDashboardView.vue')
const CentralOperatorsView = () => import('../views/central/CentralOperatorsView.vue')
const CentralRolesView = () => import('../views/central/CentralRolesView.vue')
const PortalLoginView = () => import('../views/portal/PortalLoginView.vue')
const PortalCatalogueView = () => import('../views/portal/PortalCatalogueView.vue')
const PortalCartView = () => import('../views/portal/PortalCartView.vue')
const PortalOrdersView = () => import('../views/portal/PortalOrdersView.vue')
const PortalPriceListView = () => import('../views/portal/PortalPriceListView.vue')
const CentralRegisterView = () => import('../views/central/CentralRegisterView.vue')
const CentralVerifyView = () => import('../views/central/CentralVerifyView.vue')
const CentralProvisioningView = () => import('../views/central/CentralProvisioningView.vue')
const BillingView = () => import('../views/BillingView.vue')
const DashboardView = () => import('../views/DashboardView.vue')
const CrudView = () => import('../views/CrudView.vue')
const CategoriesView = () => import('../views/CategoriesView.vue')
const ProductsView = () => import('../views/ProductsView.vue')
const PurchaseIndentsView = () => import('../views/PurchaseIndentsView.vue')
const RfqsView = () => import('../views/RfqsView.vue')
const PurchaseOrdersView = () => import('../views/PurchaseOrdersView.vue')
const VendorRatingsView = () => import('../views/VendorRatingsView.vue')
const PurchaseInvoicesView = () => import('../views/PurchaseInvoicesView.vue')
const StockView = () => import('../views/StockView.vue')
const StockLedgerView = () => import('../views/StockLedgerView.vue')
const StockAdjustmentsView = () => import('../views/StockAdjustmentsView.vue')
const StockTransfersView = () => import('../views/StockTransfersView.vue')
const StockReturnsView = () => import('../views/StockReturnsView.vue')
const GateEntriesView = () => import('../views/GateEntriesView.vue')
const UsersView = () => import('../views/UsersView.vue')
const RolesView = () => import('../views/RolesView.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const WarehousesView = () => import('../views/WarehousesView.vue')
const SuppliersView = () => import('../views/SuppliersView.vue')
const CustomersView = () => import('../views/CustomersView.vue')
const BrokerLedgerView = () => import('../views/BrokerLedgerView.vue')
const TailorWorkView = () => import('../views/TailorWorkView.vue')
const TailorPaymentView = () => import('../views/TailorPaymentView.vue')
const TailorLedgerView = () => import('../views/TailorLedgerView.vue')
const TailorBonusView = () => import('../views/TailorBonusView.vue')
const TailorPerformanceView = () => import('../views/TailorPerformanceView.vue')
const EmbroideryWorkView = () => import('../views/EmbroideryWorkView.vue')
const EmbroideryPaymentView = () => import('../views/EmbroideryPaymentView.vue')
const EmbroideryLedgerView = () => import('../views/EmbroideryLedgerView.vue')
const CustomerCategoriesView = () => import('../views/CustomerCategoriesView.vue')
const LeadsView = () => import('../views/LeadsView.vue')
const FollowUpsView = () => import('../views/FollowUpsView.vue')
const ReferralsView = () => import('../views/ReferralsView.vue')
const OccasionsView = () => import('../views/OccasionsView.vue')
const WhatsAppView = () => import('../views/WhatsAppView.vue')
const SmsView = () => import('../views/SmsView.vue')
const MarketingCampaignsView = () => import('../views/MarketingCampaignsView.vue')
const EmailLogsView = () => import('../views/EmailLogsView.vue')
const SalesmanTargetsView = () => import('../views/SalesmanTargetsView.vue')
const BeatsView = () => import('../views/BeatsView.vue')
const SalesmanVisitsView = () => import('../views/SalesmanVisitsView.vue')
const SalesmanTrackingView = () => import('../views/SalesmanTrackingView.vue')
const SalesmanCollectionsView = () => import('../views/SalesmanCollectionsView.vue')
const SalesmanExpensesView = () => import('../views/SalesmanExpensesView.vue')
const SalesmanReportsView = () => import('../views/SalesmanReportsView.vue')
const SalesOrdersView = () => import('../views/SalesOrdersView.vue')
const SalesQuotationsView = () => import('../views/SalesQuotationsView.vue')
const PosView = () => import('../views/PosView.vue')
const SalesReturnsView = () => import('../views/SalesReturnsView.vue')
const B2bOrdersView = () => import('../views/B2bOrdersView.vue')
const EcommerceIntegrationsView = () => import('../views/EcommerceIntegrationsView.vue')
const ChannelOrdersView = () => import('../views/ChannelOrdersView.vue')
const ChannelListingsView = () => import('../views/ChannelListingsView.vue')
const ChannelSyncLogsView = () => import('../views/ChannelSyncLogsView.vue')
const BomsView = () => import('../views/BomsView.vue')
const ManufacturingOrdersView = () => import('../views/ManufacturingOrdersView.vue')
const JobWorksView = () => import('../views/JobWorksView.vue')
const FabricRollsView = () => import('../views/FabricRollsView.vue')
const FabricInspectionsView = () => import('../views/FabricInspectionsView.vue')
const ShrinkageRecordsView = () => import('../views/ShrinkageRecordsView.vue')
const FabricCuttingsView = () => import('../views/FabricCuttingsView.vue')
const ExpensesView = () => import('../views/ExpensesView.vue')
const EmployeeClaimsView = () => import('../views/EmployeeClaimsView.vue')
const VehicleExpensesView = () => import('../views/VehicleExpensesView.vue')
const FuelExpensesView = () => import('../views/FuelExpensesView.vue')
const ElectricityBillsView = () => import('../views/ElectricityBillsView.vue')
const FactoryMaintenanceView = () => import('../views/FactoryMaintenanceView.vue')
const AssetMaintenanceView = () => import('../views/AssetMaintenanceView.vue')
const DispatchPlansView = () => import('../views/DispatchPlansView.vue')
const ProofOfDeliveryView = () => import('../views/ProofOfDeliveryView.vue')
const VouchersView = () => import('../views/VouchersView.vue')
const CashBookView = () => import('../views/CashBookView.vue')
const PettyCashView = () => import('../views/PettyCashView.vue')
const TrialBalanceView = () => import('../views/TrialBalanceView.vue')
const ProfitLossView = () => import('../views/ProfitLossView.vue')
const BalanceSheetView = () => import('../views/BalanceSheetView.vue')
const BankBookView = () => import('../views/BankBookView.vue')
const JournalView = () => import('../views/JournalView.vue')
const DayBookView = () => import('../views/DayBookView.vue')
const CustomerLedgerView = () => import('../views/CustomerLedgerView.vue')
const SupplierLedgerView = () => import('../views/SupplierLedgerView.vue')
const ExpenseLedgerView = () => import('../views/ExpenseLedgerView.vue')
const PriceListsView = () => import('../views/PriceListsView.vue')
const CycleCountsView = () => import('../views/CycleCountsView.vue')
const BatchesView = () => import('../views/BatchesView.vue')
const SerialNumbersView = () => import('../views/SerialNumbersView.vue')
const InventoryAlertsView = () => import('../views/InventoryAlertsView.vue')
const DeadStockView = () => import('../views/DeadStockView.vue')
const ReorderView = () => import('../views/ReorderView.vue')
const ReportsView = () => import('../views/ReportsView.vue')
const BarcodeScanView = () => import('../views/BarcodeScanView.vue')
const LabelPrintingView = () => import('../views/LabelPrintingView.vue')
const AuditLogsView = () => import('../views/AuditLogsView.vue')
const BackupView = () => import('../views/BackupView.vue')
const GstToolsView = () => import('../views/GstToolsView.vue')
const EInvoicesView = () => import('../views/EInvoicesView.vue')
const SearchResultsView = () => import('../views/SearchResultsView.vue')
const EWayBillsView = () => import('../views/EWayBillsView.vue')
const NotificationsView = () => import('../views/NotificationsView.vue')
const LeaveApplicationsView = () => import('../views/LeaveApplicationsView.vue')
const OvertimeView = () => import('../views/OvertimeView.vue')
const EmployeeBonusesView = () => import('../views/EmployeeBonusesView.vue')
const SalaryAdvancesView = () => import('../views/SalaryAdvancesView.vue')
const EmployeeLoansView = () => import('../views/EmployeeLoansView.vue')
const BiometricPunchesView = () => import('../views/BiometricPunchesView.vue')
const PayrollView = () => import('../views/PayrollView.vue')
const CustomerDisplayView = () => import('../views/CustomerDisplayView.vue')
const PaymentGatewaysView = () => import('../views/PaymentGatewaysView.vue')
const PaymentTransactionsView = () => import('../views/PaymentTransactionsView.vue')
const PayView = () => import('../views/PayView.vue')
const AiInsightsView = () => import('../views/AiInsightsView.vue')
const AiAssistantView = () => import('../views/AiAssistantView.vue')

// Single-domain app. Public routes (login + self-serve signup / verification /
// provisioning status) sit alongside the authenticated tenant application; the
// tenant is resolved server-side from the logged-in user, not from the host.
const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
  { path: '/register', name: 'register', component: CentralRegisterView, meta: { public: true } },
  { path: '/verify', name: 'central-verify', component: CentralVerifyView, meta: { public: true } },
  { path: '/provisioning', name: 'central-provisioning', component: CentralProvisioningView, meta: { public: true } },
  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: DashboardView },
      { path: 'billing', name: 'billing', component: BillingView },
      // Global omni-search results (§22). No permission gate: the API itself
      // only returns groups the caller is allowed to view.
      { path: 'search', name: 'search', component: SearchResultsView },
      { path: 'notifications', component: NotificationsView },
      { path: 'products', component: ProductsView, meta: { permission: 'products.view' } },
      { path: 'categories', component: CategoriesView, meta: { permission: 'categories.view' } },
      { path: 'brands', component: CrudView, meta: { resource: 'brands', permission: 'brands.view' } },
      { path: 'units', component: CrudView, meta: { resource: 'units', permission: 'units.view' } },
      { path: 'warehouses', component: WarehousesView, meta: { permission: 'warehouses.view' } },
      { path: 'suppliers', component: SuppliersView, meta: { permission: 'suppliers.view' } },
      { path: 'customers', component: CustomersView, meta: { permission: 'customers.view' } },
      { path: 'customer-categories', component: CustomerCategoriesView, meta: { permission: 'customer-categories.view' } },
      { path: 'price-lists', component: PriceListsView, meta: { permission: 'price-lists.view' } },
      { path: 'brokers/ledger', component: BrokerLedgerView, meta: { permission: 'brokers.view' } },
      { path: 'brokers', component: CrudView, meta: { resource: 'brokers', permission: 'brokers.view' } },
      { path: 'leads', component: LeadsView, meta: { permission: 'leads.view' } },
      { path: 'follow-ups', component: FollowUpsView, meta: { permission: 'follow-ups.view' } },
      { path: 'coupons', component: CrudView, meta: { resource: 'coupons', permission: 'coupons.view' } },
      { path: 'referrals', component: ReferralsView, meta: { permission: 'referrals.view' } },
      { path: 'crm/occasions', component: OccasionsView, meta: { permission: 'customers.view' } },
      { path: 'whatsapp', component: WhatsAppView, meta: { permission: 'whatsapp.view' } },
      { path: 'sms', component: SmsView, meta: { permission: 'sms.view' } },
      { path: 'marketing-campaigns', component: MarketingCampaignsView, meta: { permission: 'marketing-campaigns.view' } },
      { path: 'email-logs', component: EmailLogsView, meta: { permission: 'email.view' } },
      { path: 'salesmen', component: CrudView, meta: { resource: 'salesmen', permission: 'salesmen.view' } },
      { path: 'salesman-targets', component: SalesmanTargetsView, meta: { permission: 'salesman-targets.view' } },
      { path: 'beats', component: BeatsView, meta: { permission: 'beats.view' } },
      { path: 'salesman-visits', component: SalesmanVisitsView, meta: { permission: 'salesman-visits.view' } },
      { path: 'salesman-tracking', component: SalesmanTrackingView, meta: { permission: 'salesman-locations.view' } },
      { path: 'salesman-collections', component: SalesmanCollectionsView, meta: { permission: 'salesman-collections.view' } },
      { path: 'salesman-expenses', component: SalesmanExpensesView, meta: { permission: 'salesman-expenses.view' } },
      { path: 'salesman-reports', component: SalesmanReportsView, meta: { permission: 'salesmen.view' } },
      { path: 'tailors', component: CrudView, meta: { resource: 'tailors', permission: 'tailors.view' } },
      { path: 'contractors', component: CrudView, meta: { resource: 'contractors', permission: 'contractors.view' } },
      { path: 'rate-cards', component: CrudView, meta: { resource: 'rate-cards', permission: 'rate-cards.view' } },
      { path: 'piece-rates', component: CrudView, meta: { resource: 'piece-rates', permission: 'piece-rates.view' } },
      { path: 'tailor-works/pending', component: TailorWorkView, meta: { workMode: 'pending', permission: 'tailor-works.view' } },
      { path: 'tailor-works/completed', component: TailorWorkView, meta: { workMode: 'completed', permission: 'tailor-works.view' } },
      { path: 'tailor-alterations', component: CrudView, meta: { resource: 'tailor-alterations', permission: 'tailor-alterations.view' } },
      { path: 'tailor-bonuses', component: TailorBonusView, meta: { permission: 'tailor-bonuses.view' } },
      { path: 'tailor-performance', component: TailorPerformanceView, meta: { permission: 'reports.view' } },
      { path: 'tailor-payments', component: TailorPaymentView, meta: { permission: 'tailor-payments.view' } },
      { path: 'tailors/ledger', component: TailorLedgerView, meta: { permission: 'tailors.view' } },
      { path: 'embroidery-vendors', component: CrudView, meta: { resource: 'embroidery-vendors', permission: 'embroidery-vendors.view' } },
      { path: 'embroidery-works/pending', component: EmbroideryWorkView, meta: { workMode: 'pending', permission: 'embroidery-works.view' } },
      { path: 'embroidery-works/received', component: EmbroideryWorkView, meta: { workMode: 'completed', permission: 'embroidery-works.view' } },
      { path: 'embroidery-payments', component: EmbroideryPaymentView, meta: { permission: 'embroidery-payments.view' } },
      { path: 'embroidery-vendors/ledger', component: EmbroideryLedgerView, meta: { permission: 'embroidery-vendors.view' } },
      { path: 'purchase-indents', component: PurchaseIndentsView, meta: { permission: 'purchase-indents.view' } },
      { path: 'rfqs', component: RfqsView, meta: { permission: 'rfqs.view' } },
      { path: 'purchase-orders', component: PurchaseOrdersView, meta: { permission: 'purchases.view' } },
      { path: 'vendor-ratings', component: VendorRatingsView, meta: { permission: 'vendor-ratings.view' } },
      { path: 'purchase-invoices', component: PurchaseInvoicesView, meta: { permission: 'purchase-invoices.view' } },
      { path: 'sales-quotations', component: SalesQuotationsView, meta: { permission: 'sales.view' } },
      { path: 'sales-orders', component: SalesOrdersView, meta: { permission: 'sales.view' } },
      { path: 'pos', component: PosView, meta: { permission: 'sales.create' } },
      { path: 'sales-returns', component: SalesReturnsView, meta: { permission: 'sales-returns.view' } },
      { path: 'b2b-orders', component: B2bOrdersView, meta: { permission: 'b2b-orders.view' } },
      { path: 'ecommerce-integrations', component: EcommerceIntegrationsView, meta: { permission: 'ecommerce-integrations.view' } },
      { path: 'channel-orders', component: ChannelOrdersView, meta: { permission: 'channel-orders.view' } },
      { path: 'channel-listings', component: ChannelListingsView, meta: { permission: 'channel-listings.view' } },
      { path: 'channel-sync-logs', component: ChannelSyncLogsView, meta: { permission: 'channel-sync-logs.view' } },
      { path: 'boms', component: BomsView, meta: { permission: 'boms.view' } },
      { path: 'manufacturing-orders', component: ManufacturingOrdersView, meta: { permission: 'manufacturing-orders.view' } },
      { path: 'colour-printings', component: CrudView, meta: { resource: 'colour-printings', permission: 'colour-printing.view' } },
      { path: 'job-works', component: JobWorksView, meta: { permission: 'job-works.view' } },
      { path: 'fabric-rolls', component: FabricRollsView, meta: { permission: 'textile.view' } },
      { path: 'fabric-inspections', component: FabricInspectionsView, meta: { permission: 'textile.view' } },
      { path: 'shrinkage-records', component: ShrinkageRecordsView, meta: { permission: 'textile.view' } },
      { path: 'fabric-cuttings', component: FabricCuttingsView, meta: { permission: 'textile.view' } },
      { path: 'expenses', component: ExpensesView, meta: { permission: 'expenses.view' } },
      { path: 'expense-categories', component: CrudView, meta: { resource: 'expense-categories', permission: 'expense-categories.view' } },
      { path: 'employee-claims', component: EmployeeClaimsView, meta: { permission: 'employee-claims.view' } },
      { path: 'vehicle-expenses', component: VehicleExpensesView, meta: { permission: 'vehicle-expenses.view' } },
      { path: 'fuel-expenses', component: FuelExpensesView, meta: { permission: 'fuel-expenses.view' } },
      { path: 'electricity-bills', component: ElectricityBillsView, meta: { permission: 'electricity-bills.view' } },
      { path: 'factory-maintenances', component: FactoryMaintenanceView, meta: { permission: 'factory-maintenances.view' } },
      { path: 'asset-maintenances', component: AssetMaintenanceView, meta: { permission: 'asset-maintenances.view' } },
      { path: 'vehicles', component: CrudView, meta: { resource: 'vehicles', permission: 'vehicles.view' } },
      { path: 'assets', component: CrudView, meta: { resource: 'assets', permission: 'assets.view' } },
      // Transport & Logistics
      { path: 'drivers', component: CrudView, meta: { resource: 'drivers', permission: 'drivers.view' } },
      { path: 'delivery-routes', component: CrudView, meta: { resource: 'delivery-routes', permission: 'delivery-routes.view' } },
      { path: 'dispatch-plans', component: DispatchPlansView, meta: { permission: 'dispatch-plans.view' } },
      { path: 'proof-of-delivery', component: ProofOfDeliveryView, meta: { permission: 'dispatch-pods.view' } },
      { path: 'transport-charges', component: CrudView, meta: { resource: 'transport-charges', permission: 'transport-charges.view' } },
      { path: 'bank-accounts', component: CrudView, meta: { resource: 'bank-accounts', permission: 'bank-accounts.view' } },
      { path: 'vouchers', component: VouchersView, meta: { permission: 'vouchers.view' } },
      { path: 'accounts/cash-book', component: CashBookView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/bank-book', component: BankBookView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/journal', component: JournalView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/day-book', component: DayBookView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/petty-cash', component: PettyCashView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/trial-balance', component: TrialBalanceView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/profit-loss', component: ProfitLossView, meta: { permission: 'accounts.view' } },
      { path: 'accounts/balance-sheet', component: BalanceSheetView, meta: { permission: 'accounts.view' } },
      { path: 'tds-tcs-rates', component: CrudView, meta: { resource: 'tds-tcs-rates', permission: 'tds-tcs-rates.view' } },
      { path: 'accounts/customer-ledger', component: CustomerLedgerView, meta: { permission: 'customers.view' } },
      { path: 'accounts/supplier-ledger', component: SupplierLedgerView, meta: { permission: 'suppliers.view' } },
      { path: 'accounts/expense-ledger', component: ExpenseLedgerView, meta: { permission: 'expenses.view' } },
      { path: 'stock', component: StockView, meta: { permission: 'stock.view' } },
      { path: 'stock-ledger', component: StockLedgerView, meta: { permission: 'stock-ledger.view' } },
      { path: 'cycle-counts', component: CycleCountsView, meta: { permission: 'cycle-counts.view' } },
      { path: 'stock-adjustments', component: StockAdjustmentsView, meta: { permission: 'stock-adjustments.view' } },
      { path: 'stock-transfers', component: StockTransfersView, meta: { permission: 'stock-transfers.view' } },
      { path: 'stock-returns', component: StockReturnsView, meta: { permission: 'stock-returns.view' } },
      { path: 'gate-entries', component: GateEntriesView, meta: { permission: 'gate-entries.view' } },
      { path: 'batches', component: BatchesView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'serial-numbers', component: SerialNumbersView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'inventory-alerts', component: InventoryAlertsView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'dead-stock', component: DeadStockView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'reorder', component: ReorderView, meta: { permission: 'advanced-inventory.view' } },
      { path: 'reports/:tab?', component: ReportsView, meta: { permission: 'reports.view' } },
      { path: 'barcode-scan', component: BarcodeScanView, meta: { permission: 'barcodes.view' } },
      { path: 'label-printing', component: LabelPrintingView, meta: { permission: 'barcodes.view' } },
      { path: 'departments', component: CrudView, meta: { resource: 'departments', permission: 'departments.view' } },
      { path: 'employees', component: CrudView, meta: { resource: 'employees', permission: 'employees.view' } },
      { path: 'attendance', component: CrudView, meta: { resource: 'attendance', permission: 'attendance.view' } },
      { path: 'salaries', component: CrudView, meta: { resource: 'salaries', permission: 'salaries.view' } },
      { path: 'leave-types', component: CrudView, meta: { resource: 'leave-types', permission: 'leave-types.view' } },
      { path: 'leave-applications', component: LeaveApplicationsView, meta: { permission: 'leave-applications.view' } },
      { path: 'overtime', component: OvertimeView, meta: { permission: 'overtime.view' } },
      { path: 'employee-bonuses', component: EmployeeBonusesView, meta: { permission: 'employee-bonuses.view' } },
      { path: 'salary-advances', component: SalaryAdvancesView, meta: { permission: 'salary-advances.view' } },
      { path: 'employee-loans', component: EmployeeLoansView, meta: { permission: 'employee-loans.view' } },
      { path: 'salary-structures', component: CrudView, meta: { resource: 'salary-structures', permission: 'salary-structures.view' } },
      { path: 'biometric-devices', component: CrudView, meta: { resource: 'biometric-devices', permission: 'biometric-devices.view' } },
      { path: 'biometric-punches', component: BiometricPunchesView, meta: { permission: 'biometric-punches.view' } },
      { path: 'payroll', component: PayrollView, meta: { permission: 'payroll.view' } },
      { path: 'users', component: UsersView, meta: { permission: 'users.view' } },
      { path: 'roles', component: RolesView, meta: { permission: 'roles.view' } },
      { path: 'settings', component: SettingsView, meta: { permission: 'settings.view' } },
      { path: 'system-tools', component: () => import('../views/SystemToolsView.vue'), meta: { permission: 'settings.view' } },
      { path: 'workflows', component: () => import('../views/WorkflowsView.vue'), meta: { permission: 'workflows.view' } },
      { path: 'audit-logs', component: AuditLogsView, meta: { permission: 'audit-logs.view' } },
      { path: 'companies', component: CrudView, meta: { resource: 'companies', permission: 'companies.view' } },
      { path: 'branches', component: CrudView, meta: { resource: 'branches', permission: 'branches.view' } },
      { path: 'backups', component: BackupView, meta: { permission: 'backups.view' } },
      // Payment Gateways (§16)
      { path: 'payment-gateways', component: PaymentGatewaysView, meta: { permission: 'payment-gateways.view' } },
      { path: 'payment-transactions', component: PaymentTransactionsView, meta: { permission: 'payments.view' } },
      // GST Integration (§20)
      { path: 'gst-registrations', component: CrudView, meta: { resource: 'gst-registrations', permission: 'gst-registrations.view' } },
      { path: 'hsn-codes', component: CrudView, meta: { resource: 'hsn-codes', permission: 'hsn-codes.view' } },
      { path: 'gst-tools', component: GstToolsView, meta: { permission: 'hsn-codes.view' } },
      { path: 'e-invoices', component: EInvoicesView, meta: { permission: 'e-invoices.view' } },
      { path: 'e-way-bills', component: EWayBillsView, meta: { permission: 'e-way-bills.view' } },
      // AI Features (§22)
      { path: 'ai-assistant', component: AiAssistantView, meta: { permission: 'ai.view' } },
      { path: 'ai-insights', component: AiInsightsView, meta: { permission: 'ai.view' } },
    ],
  },
  // Full-screen POS customer-facing display (own window on a 2nd monitor).
  // Outside AppLayout: no chrome, mirrors the terminal over a BroadcastChannel.
  { path: '/pos-display', name: 'pos-display', component: CustomerDisplayView, meta: { requiresAuth: true } },

  // Public hosted pay page (SRS §16): customers open a payment link here — no
  // login. Outside AppLayout; the tenant is derived server-side from the token.
  { path: '/pay/:token', name: 'pay', component: PayView, meta: { public: true } },

  // B2B customer portal (SRS §14): a separate authenticated surface for
  // dealers/distributors/retailers, gated by its own portal token.
  { path: '/portal/login', name: 'portal-login', component: PortalLoginView, meta: { portal: true, portalPublic: true } },
  {
    path: '/portal',
    component: PortalLayout,
    meta: { portal: true },
    children: [
      { path: '', name: 'portal-catalogue', component: PortalCatalogueView },
      { path: 'price-list', name: 'portal-price-list', component: PortalPriceListView },
      { path: 'cart', name: 'portal-cart', component: PortalCartView },
      { path: 'orders', name: 'portal-orders', component: PortalOrdersView },
    ],
  },
  // SaaS-owner platform panel (Level-1 RBAC): platform operators managing the
  // SaaS itself — tenants, billing, support, ops. Own token/guard, separate
  // from staff and portal. Authenticates against /api/central/*.
  { path: '/platform/login', name: 'central-login', component: CentralLoginView, meta: { central: true, centralPublic: true } },
  {
    path: '/platform',
    component: CentralAdminLayout,
    meta: { central: true },
    children: [
      { path: '', name: 'central-dashboard', component: CentralDashboardView },
      { path: 'operators', name: 'central-operators', component: CentralOperatorsView, meta: { permission: 'operators.view' } },
      { path: 'roles', name: 'central-roles', component: CentralRolesView, meta: { permission: 'roles.view' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Drive the global top progress bar around each navigation so lazy route-chunk
// fetches (see the dynamic imports above) show visible feedback instead of a
// frozen screen. finish() runs on success, redirect, and failure alike.
const routeProgress = useRouteProgress()
router.beforeEach((to, from) => {
  if (to.path !== from.path) routeProgress.start()
})
router.afterEach(() => routeProgress.finish())
router.onError(() => routeProgress.finish())

router.beforeEach(async (to) => {
  // SaaS-owner platform panel has its own token/session, separate from staff.
  if (to.meta.central) {
    const central = useCentralAuthStore()
    if (to.meta.centralPublic) {
      return central.isAuthenticated && to.name === 'central-login' ? { name: 'central-dashboard' } : true
    }
    if (!central.isAuthenticated) {
      return { name: 'central-login' }
    }
    if (!central.operator) {
      try {
        await central.fetchMe()
      } catch {
        return { name: 'central-login' }
      }
    }
    if (to.meta.permission && !central.can(to.meta.permission as string)) {
      return { name: 'central-dashboard' }
    }
    return true
  }

  // B2B customer portal has its own token/session, separate from staff.
  if (to.meta.portal) {
    const portal = usePortalAuthStore()
    if (to.meta.portalPublic) {
      return portal.isAuthenticated && to.name === 'portal-login' ? { name: 'portal-catalogue' } : true
    }
    if (!portal.isAuthenticated) {
      return { name: 'portal-login' }
    }
    if (!portal.customer) {
      try {
        await portal.fetchMe()
      } catch {
        return { name: 'portal-login' }
      }
    }
    return true
  }

  const auth = useAuthStore()

  if (to.meta.public) {
    return auth.isAuthenticated && to.name === 'login' ? { path: '/' } : true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login' }
  }

  // Ensure the user profile (roles/permissions) is loaded before guarding.
  if (!auth.user) {
    try {
      await auth.fetchUser()
    } catch {
      return { name: 'login' }
    }
  }

  if (to.meta.permission && !auth.can(to.meta.permission as string)) {
    return { path: '/' }
  }

  return true
})

export default router
