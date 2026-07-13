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
import PurchaseOrdersView from '../views/PurchaseOrdersView.vue'
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
import CustomerCategoriesView from '../views/CustomerCategoriesView.vue'
import SalesOrdersView from '../views/SalesOrdersView.vue'
import SalesQuotationsView from '../views/SalesQuotationsView.vue'
import PosView from '../views/PosView.vue'
import SalesReturnsView from '../views/SalesReturnsView.vue'
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
      { path: 'purchase-orders', component: PurchaseOrdersView, meta: { permission: 'purchases.view' } },
      { path: 'purchase-invoices', component: PurchaseInvoicesView, meta: { permission: 'purchase-invoices.view' } },
      { path: 'sales-quotations', component: SalesQuotationsView, meta: { permission: 'sales.view' } },
      { path: 'sales-orders', component: SalesOrdersView, meta: { permission: 'sales.view' } },
      { path: 'pos', component: PosView, meta: { permission: 'sales.create' } },
      { path: 'sales-returns', component: SalesReturnsView, meta: { permission: 'sales-returns.view' } },
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
