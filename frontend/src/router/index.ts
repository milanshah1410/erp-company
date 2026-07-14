import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AppLayout from '../layouts/AppLayout.vue'
import LoginView from '../views/LoginView.vue'
import CentralRegisterView from '../views/central/CentralRegisterView.vue'
import CentralVerifyView from '../views/central/CentralVerifyView.vue'
import CentralProvisioningView from '../views/central/CentralProvisioningView.vue'
import BillingView from '../views/BillingView.vue'
import DashboardView from '../views/DashboardView.vue'
import CrudView from '../views/CrudView.vue'
import CategoriesView from '../views/CategoriesView.vue'
import ProductsView from '../views/ProductsView.vue'
import PurchaseIndentsView from '../views/PurchaseIndentsView.vue'
import RfqsView from '../views/RfqsView.vue'
import PurchaseOrdersView from '../views/PurchaseOrdersView.vue'
import VendorRatingsView from '../views/VendorRatingsView.vue'
import PurchaseInvoicesView from '../views/PurchaseInvoicesView.vue'
import StockView from '../views/StockView.vue'
import StockLedgerView from '../views/StockLedgerView.vue'
import StockAdjustmentsView from '../views/StockAdjustmentsView.vue'
import StockTransfersView from '../views/StockTransfersView.vue'
import StockReturnsView from '../views/StockReturnsView.vue'
import GateEntriesView from '../views/GateEntriesView.vue'
import UsersView from '../views/UsersView.vue'
import RolesView from '../views/RolesView.vue'
import SettingsView from '../views/SettingsView.vue'
import WarehousesView from '../views/WarehousesView.vue'
import SuppliersView from '../views/SuppliersView.vue'
import CustomersView from '../views/CustomersView.vue'
import BrokerLedgerView from '../views/BrokerLedgerView.vue'
import TailorWorkView from '../views/TailorWorkView.vue'
import TailorPaymentView from '../views/TailorPaymentView.vue'
import TailorLedgerView from '../views/TailorLedgerView.vue'
import TailorBonusView from '../views/TailorBonusView.vue'
import TailorPerformanceView from '../views/TailorPerformanceView.vue'
import EmbroideryWorkView from '../views/EmbroideryWorkView.vue'
import EmbroideryPaymentView from '../views/EmbroideryPaymentView.vue'
import EmbroideryLedgerView from '../views/EmbroideryLedgerView.vue'
import CustomerCategoriesView from '../views/CustomerCategoriesView.vue'
import LeadsView from '../views/LeadsView.vue'
import FollowUpsView from '../views/FollowUpsView.vue'
import ReferralsView from '../views/ReferralsView.vue'
import OccasionsView from '../views/OccasionsView.vue'
import WhatsAppView from '../views/WhatsAppView.vue'
import SalesmanTargetsView from '../views/SalesmanTargetsView.vue'
import BeatsView from '../views/BeatsView.vue'
import SalesmanVisitsView from '../views/SalesmanVisitsView.vue'
import SalesmanTrackingView from '../views/SalesmanTrackingView.vue'
import SalesmanCollectionsView from '../views/SalesmanCollectionsView.vue'
import SalesmanExpensesView from '../views/SalesmanExpensesView.vue'
import SalesmanReportsView from '../views/SalesmanReportsView.vue'
import SalesOrdersView from '../views/SalesOrdersView.vue'
import SalesQuotationsView from '../views/SalesQuotationsView.vue'
import PosView from '../views/PosView.vue'
import SalesReturnsView from '../views/SalesReturnsView.vue'
import BomsView from '../views/BomsView.vue'
import ManufacturingOrdersView from '../views/ManufacturingOrdersView.vue'
import JobWorksView from '../views/JobWorksView.vue'
import FabricRollsView from '../views/FabricRollsView.vue'
import FabricInspectionsView from '../views/FabricInspectionsView.vue'
import ShrinkageRecordsView from '../views/ShrinkageRecordsView.vue'
import FabricCuttingsView from '../views/FabricCuttingsView.vue'
import ExpensesView from '../views/ExpensesView.vue'
import EmployeeClaimsView from '../views/EmployeeClaimsView.vue'
import VehicleExpensesView from '../views/VehicleExpensesView.vue'
import FuelExpensesView from '../views/FuelExpensesView.vue'
import ElectricityBillsView from '../views/ElectricityBillsView.vue'
import FactoryMaintenanceView from '../views/FactoryMaintenanceView.vue'
import AssetMaintenanceView from '../views/AssetMaintenanceView.vue'
import DispatchPlansView from '../views/DispatchPlansView.vue'
import ProofOfDeliveryView from '../views/ProofOfDeliveryView.vue'
import VouchersView from '../views/VouchersView.vue'
import CashBookView from '../views/CashBookView.vue'
import PettyCashView from '../views/PettyCashView.vue'
import TrialBalanceView from '../views/TrialBalanceView.vue'
import ProfitLossView from '../views/ProfitLossView.vue'
import BalanceSheetView from '../views/BalanceSheetView.vue'
import BankBookView from '../views/BankBookView.vue'
import JournalView from '../views/JournalView.vue'
import DayBookView from '../views/DayBookView.vue'
import CustomerLedgerView from '../views/CustomerLedgerView.vue'
import SupplierLedgerView from '../views/SupplierLedgerView.vue'
import ExpenseLedgerView from '../views/ExpenseLedgerView.vue'
import PriceListsView from '../views/PriceListsView.vue'
import CycleCountsView from '../views/CycleCountsView.vue'
import BatchesView from '../views/BatchesView.vue'
import SerialNumbersView from '../views/SerialNumbersView.vue'
import InventoryAlertsView from '../views/InventoryAlertsView.vue'
import DeadStockView from '../views/DeadStockView.vue'
import ReorderView from '../views/ReorderView.vue'
import ReportsView from '../views/ReportsView.vue'
import BarcodeScanView from '../views/BarcodeScanView.vue'
import LabelPrintingView from '../views/LabelPrintingView.vue'
import AuditLogsView from '../views/AuditLogsView.vue'
import BackupView from '../views/BackupView.vue'
import NotificationsView from '../views/NotificationsView.vue'
import CustomerDisplayView from '../views/CustomerDisplayView.vue'

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
      { path: 'users', component: UsersView, meta: { permission: 'users.view' } },
      { path: 'roles', component: RolesView, meta: { permission: 'roles.view' } },
      { path: 'settings', component: SettingsView, meta: { permission: 'settings.view' } },
      { path: 'audit-logs', component: AuditLogsView, meta: { permission: 'audit-logs.view' } },
      { path: 'companies', component: CrudView, meta: { resource: 'companies', permission: 'companies.view' } },
      { path: 'branches', component: CrudView, meta: { resource: 'branches', permission: 'branches.view' } },
      { path: 'backups', component: BackupView, meta: { permission: 'backups.view' } },
    ],
  },
  // Full-screen POS customer-facing display (own window on a 2nd monitor).
  // Outside AppLayout: no chrome, mirrors the terminal over a BroadcastChannel.
  { path: '/pos-display', name: 'pos-display', component: CustomerDisplayView, meta: { requiresAuth: true } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
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
