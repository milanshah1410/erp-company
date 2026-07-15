<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import { useAuthStore } from '../stores/auth'
import http from '../api/http'
import NotificationBell from '../components/NotificationBell.vue'
import GlobalSearch from '../components/GlobalSearch.vue'

// Subscription banner (trial countdown / suspended read-only notice).
const subscription = ref<{ status: string; on_trial: boolean; trial_days_left: number } | null>(null)
onMounted(async () => {
  try {
    const { data } = await http.get('/subscription')
    subscription.value = data.data
  } catch {
    /* non-blocking */
  }
})

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

// --- Company / branch context switcher ---
const switchingCompany = ref<number | null>(auth.user?.current_company?.id ?? null)
const switchingBranch = ref<number | null>(auth.user?.current_branch?.id ?? null)

const branchOptionsForCompany = computed(() =>
  (auth.user?.branches ?? []).filter((b) => b.company_id === switchingCompany.value),
)

async function onCompanyChange(companyId: number) {
  switchingBranch.value = null
  await applyContext(companyId, null)
}

async function onBranchChange(branchId: number | null) {
  if (!switchingCompany.value) return
  await applyContext(switchingCompany.value, branchId)
}

async function applyContext(companyId: number, branchId: number | null) {
  try {
    await auth.switchContext(companyId, branchId)
    switchingCompany.value = auth.user?.current_company?.id ?? null
    switchingBranch.value = auth.user?.current_branch?.id ?? null
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Could not switch context', detail: e.response?.data?.message || 'Unexpected error', life: 4000 })
  }
}

interface NavLeaf {
  label: string
  icon: string
  to: string
  permission?: string
}

interface NavGroup {
  label: string
  icon: string
  children: NavLeaf[]
}

type NavEntry = NavLeaf | NavGroup

function isGroup(entry: NavEntry): entry is NavGroup {
  return Array.isArray((entry as NavGroup).children)
}

// Top-menu order follows the module list in .claude/erp-company.md:
// Sales & CRM → Field Sales → Manufacturing → Textile → Tailoring/Job Work →
// Inventory → Accounting → Purchase → Expenses → HR & Payroll → Transport.
// Foundational "Masters" stays up front; Reports/Users/Org/Audit stay at the end.
const nav: NavEntry[] = [
  { label: 'Dashboard', icon: 'pi pi-home', to: '/' },
  {
    label: 'Masters',
    icon: 'pi pi-database',
    children: [
      { label: 'Categories', icon: 'pi pi-sitemap', to: '/categories', permission: 'categories.view' },
      { label: 'Brands', icon: 'pi pi-tag', to: '/brands', permission: 'brands.view' },
      { label: 'Units', icon: 'pi pi-percentage', to: '/units', permission: 'units.view' },
      { label: 'Suppliers', icon: 'pi pi-truck', to: '/suppliers', permission: 'suppliers.view' },
      { label: 'Products', icon: 'pi pi-box', to: '/products', permission: 'products.view' },
      { label: 'Warehouses', icon: 'pi pi-building', to: '/warehouses', permission: 'warehouses.view' },
      { label: 'Customers', icon: 'pi pi-users', to: '/customers', permission: 'customers.view' },
      { label: 'Customer Categories', icon: 'pi pi-tags', to: '/customer-categories', permission: 'customer-categories.view' },
      { label: 'Brokers', icon: 'pi pi-user-plus', to: '/brokers', permission: 'brokers.view' },
      { label: 'Broker Ledger', icon: 'pi pi-book', to: '/brokers/ledger', permission: 'brokers.view' },
      { label: 'Price Lists', icon: 'pi pi-indian-rupee', to: '/price-lists', permission: 'price-lists.view' },
    ],
  },
  {
    label: 'CRM',
    icon: 'pi pi-heart',
    children: [
      { label: 'Leads', icon: 'pi pi-filter', to: '/leads', permission: 'leads.view' },
      { label: 'Follow-ups', icon: 'pi pi-bell', to: '/follow-ups', permission: 'follow-ups.view' },
      { label: 'Occasions', icon: 'pi pi-calendar-plus', to: '/crm/occasions', permission: 'customers.view' },
      { label: 'Referrals', icon: 'pi pi-share-alt', to: '/referrals', permission: 'referrals.view' },
      { label: 'Coupons', icon: 'pi pi-ticket', to: '/coupons', permission: 'coupons.view' },
      { label: 'WhatsApp', icon: 'pi pi-whatsapp', to: '/whatsapp', permission: 'whatsapp.view' },
      { label: 'SMS', icon: 'pi pi-comment', to: '/sms', permission: 'sms.view' },
      { label: 'Marketing Campaigns', icon: 'pi pi-megaphone', to: '/marketing-campaigns', permission: 'marketing-campaigns.view' },
      { label: 'Email Log', icon: 'pi pi-envelope', to: '/email-logs', permission: 'email.view' },
    ],
  },
  {
    label: 'Sales',
    icon: 'pi pi-dollar',
    children: [
      { label: 'Sales Quotations', icon: 'pi pi-file', to: '/sales-quotations', permission: 'sales.view' },
      { label: 'Sales Orders', icon: 'pi pi-shopping-cart', to: '/sales-orders', permission: 'sales.view' },
      { label: 'POS', icon: 'pi pi-calculator', to: '/pos', permission: 'sales.create' },
      { label: 'Sales Returns', icon: 'pi pi-replay', to: '/sales-returns', permission: 'sales-returns.view' },
      { label: 'B2B Orders', icon: 'pi pi-check-square', to: '/b2b-orders', permission: 'b2b-orders.view' },
    ],
  },
  {
    label: 'E-commerce',
    icon: 'pi pi-globe',
    children: [
      { label: 'Integrations', icon: 'pi pi-link', to: '/ecommerce-integrations', permission: 'ecommerce-integrations.view' },
      { label: 'Marketplace Orders', icon: 'pi pi-inbox', to: '/channel-orders', permission: 'channel-orders.view' },
      { label: 'Channel Listings', icon: 'pi pi-tags', to: '/channel-listings', permission: 'channel-listings.view' },
      { label: 'Sync History', icon: 'pi pi-history', to: '/channel-sync-logs', permission: 'channel-sync-logs.view' },
    ],
  },
  {
    label: 'Field Sales',
    icon: 'pi pi-map',
    children: [
      { label: 'Salesmen', icon: 'pi pi-user', to: '/salesmen', permission: 'salesmen.view' },
      { label: 'Targets & Incentive', icon: 'pi pi-flag', to: '/salesman-targets', permission: 'salesman-targets.view' },
      { label: 'Beat Plan & Routes', icon: 'pi pi-directions', to: '/beats', permission: 'beats.view' },
      { label: 'Daily Visit Plan', icon: 'pi pi-calendar-clock', to: '/salesman-visits', permission: 'salesman-visits.view' },
      { label: 'Live Tracking', icon: 'pi pi-map-marker', to: '/salesman-tracking', permission: 'salesman-locations.view' },
      { label: 'Collections', icon: 'pi pi-wallet', to: '/salesman-collections', permission: 'salesman-collections.view' },
      { label: 'Daily Expenses', icon: 'pi pi-receipt', to: '/salesman-expenses', permission: 'salesman-expenses.view' },
      { label: 'Reports', icon: 'pi pi-chart-bar', to: '/salesman-reports', permission: 'salesmen.view' },
    ],
  },
  {
    label: 'Manufacturing',
    icon: 'pi pi-cog',
    children: [
      { label: 'Bills of Materials', icon: 'pi pi-sitemap', to: '/boms', permission: 'boms.view' },
      { label: 'Manufacturing Orders', icon: 'pi pi-wrench', to: '/manufacturing-orders', permission: 'manufacturing-orders.view' },
      { label: 'Colour Printing', icon: 'pi pi-palette', to: '/colour-printings', permission: 'colour-printing.view' },
      { label: 'Job Work (Outside)', icon: 'pi pi-briefcase', to: '/job-works', permission: 'job-works.view' },
    ],
  },
  {
    label: 'Textile',
    icon: 'pi pi-th-large',
    children: [
      { label: 'Fabric Rolls', icon: 'pi pi-clone', to: '/fabric-rolls', permission: 'textile.view' },
      { label: 'Fabric Inspection', icon: 'pi pi-search', to: '/fabric-inspections', permission: 'textile.view' },
      { label: 'Shrinkage Records', icon: 'pi pi-arrows-h', to: '/shrinkage-records', permission: 'textile.view' },
      { label: 'Fabric Cutting', icon: 'pi pi-scissors', to: '/fabric-cuttings', permission: 'textile.view' },
    ],
  },
  {
    label: 'Tailoring',
    icon: 'pi pi-th-large',
    children: [
      { label: 'Tailor Master', icon: 'pi pi-user', to: '/tailors', permission: 'tailors.view' },
      { label: 'Contractors', icon: 'pi pi-users', to: '/contractors', permission: 'contractors.view' },
      { label: 'Rate Card', icon: 'pi pi-list', to: '/rate-cards', permission: 'rate-cards.view' },
      { label: 'Piece Rate', icon: 'pi pi-sliders-h', to: '/piece-rates', permission: 'piece-rates.view' },
      { label: 'Pending Work', icon: 'pi pi-clock', to: '/tailor-works/pending', permission: 'tailor-works.view' },
      { label: 'Completed Work', icon: 'pi pi-check-circle', to: '/tailor-works/completed', permission: 'tailor-works.view' },
      { label: 'Alterations', icon: 'pi pi-pencil', to: '/tailor-alterations', permission: 'tailor-alterations.view' },
      { label: 'Bonus', icon: 'pi pi-star', to: '/tailor-bonuses', permission: 'tailor-bonuses.view' },
      { label: 'Performance', icon: 'pi pi-chart-line', to: '/tailor-performance', permission: 'reports.view' },
      { label: 'Payment', icon: 'pi pi-wallet', to: '/tailor-payments', permission: 'tailor-payments.view' },
      { label: 'Tailor Ledger', icon: 'pi pi-book', to: '/tailors/ledger', permission: 'tailors.view' },
    ],
  },
  {
    label: 'Embroidery',
    icon: 'pi pi-star',
    children: [
      { label: 'Vendor Master', icon: 'pi pi-user', to: '/embroidery-vendors', permission: 'embroidery-vendors.view' },
      { label: 'Pending Dispatch', icon: 'pi pi-clock', to: '/embroidery-works/pending', permission: 'embroidery-works.view' },
      { label: 'Received', icon: 'pi pi-check-circle', to: '/embroidery-works/received', permission: 'embroidery-works.view' },
      { label: 'Payment', icon: 'pi pi-wallet', to: '/embroidery-payments', permission: 'embroidery-payments.view' },
      { label: 'Vendor Ledger', icon: 'pi pi-book', to: '/embroidery-vendors/ledger', permission: 'embroidery-vendors.view' },
    ],
  },
  {
    label: 'Inventory',
    icon: 'pi pi-warehouse',
    children: [
      { label: 'Current Stock', icon: 'pi pi-database', to: '/stock', permission: 'stock.view' },
      { label: 'Stock Ledger', icon: 'pi pi-history', to: '/stock-ledger', permission: 'stock-ledger.view' },
      { label: 'Stock Adjustments', icon: 'pi pi-sliders-h', to: '/stock-adjustments', permission: 'stock-adjustments.view' },
      { label: 'Stock Transfers', icon: 'pi pi-arrows-h', to: '/stock-transfers', permission: 'stock-transfers.view' },
      { label: 'Stock Returns', icon: 'pi pi-undo', to: '/stock-returns', permission: 'stock-returns.view' },
      { label: 'Gate Entries', icon: 'pi pi-sign-in', to: '/gate-entries', permission: 'gate-entries.view' },
      { label: 'Cycle Counts', icon: 'pi pi-check-square', to: '/cycle-counts', permission: 'cycle-counts.view' },
      { label: 'Batches / Lots', icon: 'pi pi-box', to: '/batches', permission: 'advanced-inventory.view' },
      { label: 'Serial Numbers', icon: 'pi pi-qrcode', to: '/serial-numbers', permission: 'advanced-inventory.view' },
      { label: 'Inventory Alerts', icon: 'pi pi-bell', to: '/inventory-alerts', permission: 'advanced-inventory.view' },
      { label: 'Dead Stock', icon: 'pi pi-chart-line', to: '/dead-stock', permission: 'advanced-inventory.view' },
      { label: 'Reorder', icon: 'pi pi-refresh', to: '/reorder', permission: 'advanced-inventory.view' },
    ],
  },
  {
    label: 'Barcode',
    icon: 'pi pi-qrcode',
    children: [
      { label: 'Barcode Scan', icon: 'pi pi-camera', to: '/barcode-scan', permission: 'barcodes.view' },
      { label: 'Label Printing', icon: 'pi pi-print', to: '/label-printing', permission: 'barcodes.view' },
    ],
  },
  {
    label: 'Accounts',
    icon: 'pi pi-wallet',
    children: [
      { label: 'Bank & Cash Accounts', icon: 'pi pi-building', to: '/bank-accounts', permission: 'bank-accounts.view' },
      { label: 'Vouchers', icon: 'pi pi-file-edit', to: '/vouchers', permission: 'vouchers.view' },
      { label: 'Cash Book', icon: 'pi pi-money-bill', to: '/accounts/cash-book', permission: 'accounts.view' },
      { label: 'Bank Book', icon: 'pi pi-credit-card', to: '/accounts/bank-book', permission: 'accounts.view' },
      { label: 'Journal', icon: 'pi pi-book', to: '/accounts/journal', permission: 'accounts.view' },
      { label: 'Day Book', icon: 'pi pi-calendar', to: '/accounts/day-book', permission: 'accounts.view' },
      { label: 'Petty Cash', icon: 'pi pi-wallet', to: '/accounts/petty-cash', permission: 'accounts.view' },
      { label: 'Trial Balance', icon: 'pi pi-list', to: '/accounts/trial-balance', permission: 'accounts.view' },
      { label: 'Profit & Loss', icon: 'pi pi-chart-line', to: '/accounts/profit-loss', permission: 'accounts.view' },
      { label: 'Balance Sheet', icon: 'pi pi-book', to: '/accounts/balance-sheet', permission: 'accounts.view' },
      { label: 'TDS / TCS Rates', icon: 'pi pi-percentage', to: '/tds-tcs-rates', permission: 'tds-tcs-rates.view' },
      { label: 'Customer Ledger', icon: 'pi pi-user', to: '/accounts/customer-ledger', permission: 'customers.view' },
      { label: 'Supplier Ledger', icon: 'pi pi-truck', to: '/accounts/supplier-ledger', permission: 'suppliers.view' },
      { label: 'Broker Ledger', icon: 'pi pi-user-plus', to: '/brokers/ledger', permission: 'brokers.view' },
      { label: 'Expense Ledger', icon: 'pi pi-receipt', to: '/accounts/expense-ledger', permission: 'expenses.view' },
      { label: 'Payment Links', icon: 'pi pi-link', to: '/payment-transactions', permission: 'payments.view' },
      { label: 'Payment Gateways', icon: 'pi pi-credit-card', to: '/payment-gateways', permission: 'payment-gateways.view' },
    ],
  },
  {
    label: 'Purchase',
    icon: 'pi pi-shopping-bag',
    children: [
      { label: 'Purchase Indents', icon: 'pi pi-clipboard', to: '/purchase-indents', permission: 'purchase-indents.view' },
      { label: 'RFQ / Quotations', icon: 'pi pi-send', to: '/rfqs', permission: 'rfqs.view' },
      { label: 'Purchase Orders', icon: 'pi pi-file-edit', to: '/purchase-orders', permission: 'purchases.view' },
      { label: 'Vendor Ratings', icon: 'pi pi-star', to: '/vendor-ratings', permission: 'vendor-ratings.view' },
      { label: 'Vendor Invoices', icon: 'pi pi-receipt', to: '/purchase-invoices', permission: 'purchase-invoices.view' },
    ],
  },
  {
    label: 'Expenses',
    icon: 'pi pi-wallet',
    children: [
      { label: 'Expenses', icon: 'pi pi-receipt', to: '/expenses', permission: 'expenses.view' },
      { label: 'Expense Categories', icon: 'pi pi-tags', to: '/expense-categories', permission: 'expense-categories.view' },
      { label: 'Employee Claims', icon: 'pi pi-user', to: '/employee-claims', permission: 'employee-claims.view' },
      { label: 'Vehicle Expenses', icon: 'pi pi-car', to: '/vehicle-expenses', permission: 'vehicle-expenses.view' },
      { label: 'Fuel Expenses', icon: 'pi pi-bolt', to: '/fuel-expenses', permission: 'fuel-expenses.view' },
      { label: 'Electricity Bills', icon: 'pi pi-flash', to: '/electricity-bills', permission: 'electricity-bills.view' },
      { label: 'Factory Maintenance', icon: 'pi pi-wrench', to: '/factory-maintenances', permission: 'factory-maintenances.view' },
      { label: 'Asset Maintenance', icon: 'pi pi-cog', to: '/asset-maintenances', permission: 'asset-maintenances.view' },
      { label: 'Assets', icon: 'pi pi-box', to: '/assets', permission: 'assets.view' },
    ],
  },
  {
    label: 'Human Resources',
    icon: 'pi pi-id-card',
    children: [
      { label: 'Departments', icon: 'pi pi-sitemap', to: '/departments', permission: 'departments.view' },
      { label: 'Employees', icon: 'pi pi-users', to: '/employees', permission: 'employees.view' },
      { label: 'Attendance', icon: 'pi pi-calendar', to: '/attendance', permission: 'attendance.view' },
      { label: 'Biometric Devices', icon: 'pi pi-desktop', to: '/biometric-devices', permission: 'biometric-devices.view' },
      { label: 'Biometric Punches', icon: 'pi pi-fingerprint', to: '/biometric-punches', permission: 'biometric-punches.view' },
      { label: 'Leave Types', icon: 'pi pi-tags', to: '/leave-types', permission: 'leave-types.view' },
      { label: 'Leave Applications', icon: 'pi pi-calendar-times', to: '/leave-applications', permission: 'leave-applications.view' },
      { label: 'Overtime', icon: 'pi pi-clock', to: '/overtime', permission: 'overtime.view' },
    ],
  },
  {
    label: 'Payroll',
    icon: 'pi pi-wallet',
    children: [
      { label: 'Salary Structures', icon: 'pi pi-sliders-h', to: '/salary-structures', permission: 'salary-structures.view' },
      { label: 'Payroll Processing', icon: 'pi pi-cog', to: '/payroll', permission: 'payroll.view' },
      { label: 'Salary Register', icon: 'pi pi-wallet', to: '/salaries', permission: 'salaries.view' },
      { label: 'Bonuses', icon: 'pi pi-star', to: '/employee-bonuses', permission: 'employee-bonuses.view' },
      { label: 'Salary Advances', icon: 'pi pi-arrow-up-right', to: '/salary-advances', permission: 'salary-advances.view' },
      { label: 'Loans', icon: 'pi pi-credit-card', to: '/employee-loans', permission: 'employee-loans.view' },
    ],
  },
  {
    label: 'Transport & Logistics',
    icon: 'pi pi-truck',
    children: [
      { label: 'Vehicles', icon: 'pi pi-car', to: '/vehicles', permission: 'vehicles.view' },
      { label: 'Drivers', icon: 'pi pi-id-card', to: '/drivers', permission: 'drivers.view' },
      { label: 'Delivery Routes', icon: 'pi pi-directions', to: '/delivery-routes', permission: 'delivery-routes.view' },
      { label: 'Dispatch Planning', icon: 'pi pi-send', to: '/dispatch-plans', permission: 'dispatch-plans.view' },
      { label: 'Proof of Delivery', icon: 'pi pi-check-circle', to: '/proof-of-delivery', permission: 'dispatch-pods.view' },
      { label: 'Transport Charges', icon: 'pi pi-indian-rupee', to: '/transport-charges', permission: 'transport-charges.view' },
    ],
  },
  {
    label: 'Reports',
    icon: 'pi pi-chart-bar',
    children: [
      { label: 'Stock Overview', icon: 'pi pi-database', to: '/reports/stock-overview', permission: 'reports.view' },
      { label: 'Stock Movement', icon: 'pi pi-history', to: '/reports/stock-movement', permission: 'reports.view' },
      { label: 'Fast / Slow Moving', icon: 'pi pi-gauge', to: '/reports/fast-slow', permission: 'reports.view' },
      { label: 'Purchases', icon: 'pi pi-file-edit', to: '/reports/purchases', permission: 'reports.view' },
      { label: 'Vendor Performance', icon: 'pi pi-star', to: '/reports/vendor-performance', permission: 'reports.view' },
      { label: 'Payables / Pending', icon: 'pi pi-wallet', to: '/reports/payables', permission: 'reports.view' },
      { label: 'Sales', icon: 'pi pi-shopping-cart', to: '/reports/sales', permission: 'reports.view' },
      { label: 'Broker Commissions', icon: 'pi pi-percentage', to: '/reports/broker-commissions', permission: 'reports.view' },
      { label: 'Broker-wise Sales', icon: 'pi pi-users', to: '/reports/broker-wise-sales', permission: 'reports.view' },
      { label: 'Profitability', icon: 'pi pi-chart-line', to: '/reports/profitability', permission: 'reports.view' },
      { label: 'Inventory Valuation', icon: 'pi pi-wallet', to: '/reports/valuation', permission: 'reports.view' },
      { label: 'GST / Tax', icon: 'pi pi-percentage', to: '/reports/gst', permission: 'reports.view' },
      { label: 'GST Summary (GSTR-3B)', icon: 'pi pi-percentage', to: '/reports/gst-summary', permission: 'reports.view' },
      { label: 'GSTR-1 (Outward)', icon: 'pi pi-file-export', to: '/reports/gstr1', permission: 'reports.view' },
      { label: 'GSTR-2 (Inward)', icon: 'pi pi-file-import', to: '/reports/gstr2', permission: 'reports.view' },
      { label: 'TDS Register', icon: 'pi pi-minus-circle', to: '/reports/tds-register', permission: 'reports.view' },
      { label: 'TCS Register', icon: 'pi pi-plus-circle', to: '/reports/tcs-register', permission: 'reports.view' },
      { label: 'Stock Ageing', icon: 'pi pi-clock', to: '/reports/stock-ageing', permission: 'reports.view' },
      { label: 'Slow Moving Stock', icon: 'pi pi-arrow-down', to: '/reports/slow-moving', permission: 'reports.view' },
      { label: 'Fast Moving Stock', icon: 'pi pi-arrow-up', to: '/reports/fast-moving', permission: 'reports.view' },
      { label: 'Manufacturing', icon: 'pi pi-cog', to: '/reports/manufacturing', permission: 'reports.view' },
      { label: 'Production Cost', icon: 'pi pi-calculator', to: '/reports/production-cost', permission: 'reports.view' },
      { label: 'Tailor', icon: 'pi pi-user', to: '/reports/tailor', permission: 'reports.view' },
      { label: 'Embroidery', icon: 'pi pi-palette', to: '/reports/embroidery', permission: 'reports.view' },
      { label: 'Printing', icon: 'pi pi-print', to: '/reports/printing', permission: 'reports.view' },
      { label: 'Expenses', icon: 'pi pi-money-bill', to: '/reports/expenses', permission: 'reports.view' },
      { label: 'Customer Ledger', icon: 'pi pi-book', to: '/reports/customer-ledger', permission: 'reports.view' },
      { label: 'Supplier Ledger', icon: 'pi pi-book', to: '/reports/supplier-ledger', permission: 'reports.view' },
    ],
  },
  {
    label: 'GST & Compliance',
    icon: 'pi pi-verified',
    children: [
      { label: 'GST Registrations', icon: 'pi pi-id-card', to: '/gst-registrations', permission: 'gst-registrations.view' },
      { label: 'HSN / SAC Master', icon: 'pi pi-list', to: '/hsn-codes', permission: 'hsn-codes.view' },
      { label: 'GST Tools', icon: 'pi pi-search', to: '/gst-tools', permission: 'hsn-codes.view' },
      { label: 'E-Invoices (IRN)', icon: 'pi pi-qrcode', to: '/e-invoices', permission: 'e-invoices.view' },
      { label: 'E-Way Bills', icon: 'pi pi-truck', to: '/e-way-bills', permission: 'e-way-bills.view' },
    ],
  },
  {
    label: 'AI',
    icon: 'pi pi-sparkles',
    children: [
      { label: 'AI Assistant', icon: 'pi pi-comments', to: '/ai-assistant', permission: 'ai.view' },
      { label: 'AI Insights', icon: 'pi pi-chart-line', to: '/ai-insights', permission: 'ai.view' },
    ],
  },
  {
    label: 'Users & Roles',
    icon: 'pi pi-users',
    children: [
      { label: 'Users', icon: 'pi pi-user', to: '/users', permission: 'users.view' },
      { label: 'Roles & Permissions', icon: 'pi pi-shield', to: '/roles', permission: 'roles.view' },
    ],
  },
  {
    label: 'Organization',
    icon: 'pi pi-sitemap',
    children: [
      { label: 'Companies', icon: 'pi pi-sitemap', to: '/companies', permission: 'companies.view' },
      { label: 'Branches', icon: 'pi pi-map-marker', to: '/branches', permission: 'branches.view' },
      { label: 'Billing & Plan', icon: 'pi pi-credit-card', to: '/billing' },
      { label: 'Backup & Restore', icon: 'pi pi-cloud-upload', to: '/backups', permission: 'backups.view' },
      { label: 'Settings', icon: 'pi pi-cog', to: '/settings', permission: 'settings.view' },
    ],
  },
  { label: 'Audit Logs', icon: 'pi pi-history', to: '/audit-logs', permission: 'audit-logs.view' },
]

const visibleNav = computed(() =>
  nav
    .map((entry) => {
      if (!isGroup(entry)) return entry
      const children = entry.children.filter((c) => !c.permission || auth.can(c.permission))
      return children.length ? { ...entry, children } : null
    })
    .filter((entry): entry is NavEntry => entry !== null && (!isGroup(entry) ? !entry.permission || auth.can(entry.permission) : true)),
)

// --- Top-menu dropdown state ---
// A single flyout, teleported to <body> and positioned below the clicked group,
// so the horizontally-scrolling menu bar never clips it.
const route = useRoute()
const openGroup = ref<string | null>(null)
const flyoutPos = ref<{ top: number; left: number } | null>(null)

const openChildren = computed<NavLeaf[]>(() => {
  const entry = visibleNav.value.find((e) => isGroup(e) && e.label === openGroup.value)
  return entry && isGroup(entry) ? entry.children : []
})

// Small delay so the mouse can travel from the menu button into the flyout
// without the dropdown closing in the gap.
let closeTimer: ReturnType<typeof setTimeout> | null = null

function cancelClose() {
  if (closeTimer) {
    clearTimeout(closeTimer)
    closeTimer = null
  }
}

function openGroupHover(e: MouseEvent, label: string) {
  cancelClose()
  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
  flyoutPos.value = { top: rect.bottom, left: rect.left }
  openGroup.value = label
}

// Click toggles too, for touch devices where hover isn't available.
function toggleGroup(e: MouseEvent, label: string) {
  if (openGroup.value === label) {
    closeMenu()
    return
  }
  openGroupHover(e, label)
}

function scheduleClose() {
  cancelClose()
  closeTimer = setTimeout(() => {
    openGroup.value = null
    closeTimer = null
  }, 150)
}

function closeMenu() {
  cancelClose()
  openGroup.value = null
}

function isGroupActive(entry: NavGroup) {
  return entry.children.some((c) => route.path === c.to || route.path.startsWith(c.to + '/'))
}

// Close the dropdown whenever the route changes (e.g. after a click).
watch(() => route.path, closeMenu)

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') closeMenu()
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

async function logout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="layout">
    <!-- Brand + context/user row -->
    <header class="topbar">
      <RouterLink to="/" class="logo">
        <i class="pi pi-box" />
        <span>SIMS</span>
      </RouterLink>
      <GlobalSearch />
      <div class="spacer" />
      <div v-if="auth.user?.companies?.length" class="context-switcher">
        <Select
          v-model="switchingCompany"
          :options="auth.user.companies"
          optionLabel="name"
          optionValue="id"
          placeholder="Company"
          class="context-select"
          @update:modelValue="onCompanyChange"
        />
        <Select
          v-if="branchOptionsForCompany.length"
          v-model="switchingBranch"
          :options="branchOptionsForCompany"
          optionLabel="name"
          optionValue="id"
          placeholder="Branch"
          showClear
          class="context-select"
          @update:modelValue="onBranchChange"
        />
      </div>
      <NotificationBell />
      <div class="user">
        <div class="user-info">
          <span class="user-name">{{ auth.user?.name }}</span>
          <span class="user-role">{{ auth.user?.roles?.[0] }}</span>
        </div>
        <button class="logout" @click="logout" title="Logout"><i class="pi pi-sign-out" /></button>
      </div>
    </header>

    <!-- Horizontal top menu bar -->
    <nav class="topnav">
      <template v-for="entry in visibleNav" :key="entry.label">
        <RouterLink v-if="!isGroup(entry)" :to="entry.to" class="topnav-item" @click="closeMenu">
          <i :class="entry.icon" />
          <span>{{ entry.label }}</span>
        </RouterLink>

        <button
          v-else
          class="topnav-item"
          :class="{ active: isGroupActive(entry), open: openGroup === entry.label }"
          @mouseenter="openGroupHover($event, entry.label)"
          @mouseleave="scheduleClose"
          @click="toggleGroup($event, entry.label)"
        >
          <i :class="entry.icon" />
          <span>{{ entry.label }}</span>
          <i class="pi pi-chevron-down chev" />
        </button>
      </template>
    </nav>

    <!-- Dropdown flyout for the open group -->
    <Teleport to="body">
      <div
        v-if="openGroup && flyoutPos"
        class="nav-flyout"
        :style="{ top: flyoutPos.top + 'px', left: flyoutPos.left + 'px' }"
        @mouseenter="cancelClose"
        @mouseleave="scheduleClose"
      >
        <div class="flyout-title">{{ openGroup }}</div>
        <RouterLink
          v-for="child in openChildren"
          :key="child.to"
          :to="child.to"
          class="flyout-item"
          @click="closeMenu"
        >
          <i :class="child.icon" />
          <span>{{ child.label }}</span>
        </RouterLink>
      </div>
    </Teleport>

    <div class="main">
      <div
        v-if="subscription && (subscription.on_trial || subscription.status === 'suspended')"
        class="sub-banner"
        :class="subscription.status === 'suspended' ? 'danger' : 'trial'"
      >
        <i :class="subscription.status === 'suspended' ? 'pi pi-lock' : 'pi pi-clock'" />
        <span v-if="subscription.status === 'suspended'">
          Your subscription has lapsed — the workspace is read-only until you choose a plan.
        </span>
        <span v-else>
          You’re on a free trial · <strong>{{ subscription.trial_days_left }} day(s) left</strong>.
        </span>
        <RouterLink to="/billing" class="banner-cta">Choose a plan →</RouterLink>
      </div>

      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--paper, #f7f5f0);
}

/* --- Brand / context row --- */
.topbar {
  height: 60px;
  background: linear-gradient(180deg, #23292f 0%, #1e2328 100%);
  display: flex;
  align-items: center;
  padding: 0 1.25rem;
  gap: 0.5rem;
  flex-shrink: 0;
  box-shadow: 0 1px 8px rgba(0, 0, 0, 0.15);
}
.logo {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.3rem;
  font-weight: 700;
  color: #fff;
  text-decoration: none;
}
.logo i {
  font-size: 1.3rem;
  color: var(--safety-amber, #e8a33d);
}
.spacer {
  flex: 1;
}
.context-switcher {
  display: flex;
  gap: 0.5rem;
  margin-right: 1rem;
}
.context-select {
  width: 10rem;
}
.context-select :deep(.p-select) {
  font-size: 0.85rem;
}
.topbar :deep(.bell-wrap) {
  margin-right: 1rem;
}
.user {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.user-info {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  line-height: 1.2;
}
.user-name {
  font-weight: 600;
  font-size: 0.9rem;
  color: #fff;
}
.user-role {
  font-size: 0.75rem;
  color: var(--safety-amber, #e8a33d);
  font-weight: 600;
  text-transform: capitalize;
}
.logout {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.1rem;
  color: #a9b2bc;
  padding: 0.4rem;
  border-radius: 6px;
}
.logout:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}

/* --- Horizontal menu bar --- */
.topnav {
  display: flex;
  align-items: stretch;
  gap: 2px;
  padding: 0 0.75rem;
  background: linear-gradient(180deg, #2b333a 0%, #262c32 100%);
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12);
  overflow-x: auto;
  overflow-y: hidden;
  white-space: nowrap;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 30;
}
.topnav::-webkit-scrollbar {
  height: 5px;
}
.topnav::-webkit-scrollbar-track {
  background: transparent;
}
.topnav::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}
.topnav-item {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.7rem 0.9rem;
  border: none;
  border-bottom: 3px solid transparent;
  background: none;
  color: #a9b2bc;
  font-size: 0.88rem;
  font-family: inherit;
  white-space: nowrap;
  cursor: pointer;
  text-decoration: none;
  transition: background 0.15s, color 0.15s;
}
.topnav-item i:first-child {
  font-size: 0.95rem;
}
.topnav-item .chev {
  font-size: 0.65rem;
  opacity: 0.7;
  transition: transform 0.15s;
}
.topnav-item.open .chev {
  transform: rotate(180deg);
}
.topnav-item:hover {
  background: rgba(61, 99, 124, 0.3);
  color: #fff;
}
.topnav-item.open {
  background: rgba(61, 99, 124, 0.4);
  color: #fff;
}
.topnav-item.active,
.topnav-item.router-link-exact-active {
  color: #fff;
  border-bottom-color: var(--safety-amber, #e8a33d);
}
.topnav-item.active i:first-child,
.topnav-item.router-link-exact-active i:first-child {
  color: var(--safety-amber, #e8a33d);
}

/* --- Dropdown flyout --- */
.nav-flyout {
  position: fixed;
  min-width: 220px;
  max-height: calc(100vh - 130px);
  overflow-y: auto;
  background: #23292f;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 8px;
  padding: 0.5rem;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
  z-index: 41;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.nav-flyout::-webkit-scrollbar {
  width: 6px;
}
.nav-flyout::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}
.flyout-title {
  color: #7c8894;
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.25rem 0.6rem 0.4rem;
}
.flyout-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.6rem;
  border-radius: 6px;
  color: #a9b2bc;
  text-decoration: none;
  font-size: 0.85rem;
  white-space: nowrap;
}
.flyout-item i {
  width: 1.1rem;
  text-align: center;
}
.flyout-item:hover {
  background: rgba(61, 99, 124, 0.3);
  color: #fff;
}
.flyout-item.router-link-exact-active {
  color: var(--safety-amber, #e8a33d);
  background: rgba(232, 163, 61, 0.12);
}

/* --- Content --- */
.main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.content {
  padding: 1.5rem;
  flex: 1;
}
.sub-banner {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem 1.25rem;
  font-size: 0.87rem;
}
.sub-banner.trial {
  background: rgba(232, 163, 61, 0.14);
  color: #8a5a12;
  border-bottom: 1px solid rgba(232, 163, 61, 0.3);
}
.sub-banner.danger {
  background: rgba(193, 80, 46, 0.12);
  color: #a03d1f;
  border-bottom: 1px solid rgba(193, 80, 46, 0.3);
}
.banner-cta {
  margin-left: auto;
  font-weight: 700;
  text-decoration: none;
  color: inherit;
}
</style>
