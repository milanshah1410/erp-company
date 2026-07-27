<script setup lang="ts">
import { computed, type Component } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import StockOverviewReport from '../components/reports/StockOverviewReport.vue'
import StockMovementReport from '../components/reports/StockMovementReport.vue'
import FastSlowMovingReport from '../components/reports/FastSlowMovingReport.vue'
import ValuationReport from '../components/reports/ValuationReport.vue'
import PurchaseReport from '../components/reports/PurchaseReport.vue'
import VendorPerformanceReport from '../components/reports/VendorPerformanceReport.vue'
import PayablesReport from '../components/reports/PayablesReport.vue'
import SalesReport from '../components/reports/SalesReport.vue'
import ProfitabilityReport from '../components/reports/ProfitabilityReport.vue'
import GstReport from '../components/reports/GstReport.vue'
import GstSummaryReport from '../components/reports/GstSummaryReport.vue'
import Gstr1Report from '../components/reports/Gstr1Report.vue'
import Gstr2Report from '../components/reports/Gstr2Report.vue'
import TdsRegisterReport from '../components/reports/TdsRegisterReport.vue'
import TcsRegisterReport from '../components/reports/TcsRegisterReport.vue'
import BrokerCommissionReport from '../components/reports/BrokerCommissionReport.vue'
import BrokerWiseSalesReport from '../components/reports/BrokerWiseSalesReport.vue'
import ManufacturingReport from '../components/reports/ManufacturingReport.vue'
import ProductionCostReport from '../components/reports/ProductionCostReport.vue'
import TailorReport from '../components/reports/TailorReport.vue'
import EmbroideryReport from '../components/reports/EmbroideryReport.vue'
import PrintingReport from '../components/reports/PrintingReport.vue'
import ExpenseReport from '../components/reports/ExpenseReport.vue'
import CustomerLedgerReport from '../components/reports/CustomerLedgerReport.vue'
import SupplierLedgerReport from '../components/reports/SupplierLedgerReport.vue'
import StockAgeingReport from '../components/reports/StockAgeingReport.vue'
import SlowMovingReport from '../components/reports/SlowMovingReport.vue'
import FastMovingReport from '../components/reports/FastMovingReport.vue'
import ImportShipmentReport from '../components/reports/ImportShipmentReport.vue'
import ExportShipmentReport from '../components/reports/ExportShipmentReport.vue'
import ShipmentProfitReport from '../components/reports/ShipmentProfitReport.vue'
import ShipmentExpenseReport from '../components/reports/ShipmentExpenseReport.vue'
import ShipmentCustomsReport from '../components/reports/ShipmentCustomsReport.vue'
import ShipmentContainerReport from '../components/reports/ShipmentContainerReport.vue'
import CountryWiseSalesReport from '../components/reports/CountryWiseSalesReport.vue'
import PortWiseShipmentReport from '../components/reports/PortWiseShipmentReport.vue'
import PageHeader from '../components/PageHeader.vue'

interface ReportItem {
  key: string
  label: string
  component: Component
  /**
   * Modules that keep this report available — any one is enough, matching the
   * `module:a,b` gate on its API route (routes/tenant.php) and the module tags on
   * the Reports submenu. Leave unset for a report that belongs to no module.
   */
  modules: string[]
}

// Keys must match the sidebar's "Reports" submenu routes (/reports/<key>), seeded
// by MenuSeeder. The `modules` here must stay in sync with those tags — a mismatch
// shows a report in the nav that the API then refuses.
const reports: ReportItem[] = [
  { key: 'stock-overview', label: 'Stock Overview', component: StockOverviewReport, modules: ['inventory'] },
  { key: 'stock-movement', label: 'Stock Movement', component: StockMovementReport, modules: ['inventory'] },
  { key: 'fast-slow', label: 'Fast / Slow Moving', component: FastSlowMovingReport, modules: ['inventory'] },
  { key: 'purchases', label: 'Purchases', component: PurchaseReport, modules: ['purchase'] },
  { key: 'vendor-performance', label: 'Vendor Performance', component: VendorPerformanceReport, modules: ['purchase'] },
  { key: 'payables', label: 'Payables / Pending Payments', component: PayablesReport, modules: ['purchase'] },
  { key: 'sales', label: 'Sales', component: SalesReport, modules: ['sales'] },
  { key: 'broker-commissions', label: 'Broker Commissions', component: BrokerCommissionReport, modules: ['broker'] },
  { key: 'broker-wise-sales', label: 'Broker-wise Sales', component: BrokerWiseSalesReport, modules: ['broker'] },
  { key: 'profitability', label: 'Profitability', component: ProfitabilityReport, modules: ['sales'] },
  { key: 'valuation', label: 'Inventory Valuation', component: ValuationReport, modules: ['inventory'] },
  // The GST/Tax screen reads purchase-side and sales-side tax, so either the GST
  // module or one of those keeps it useful.
  { key: 'gst', label: 'GST / Tax', component: GstReport, modules: ['gst', 'purchase', 'sales'] },
  { key: 'gst-summary', label: 'GST Summary (GSTR-3B)', component: GstSummaryReport, modules: ['gst'] },
  { key: 'gstr1', label: 'GSTR-1 (Outward)', component: Gstr1Report, modules: ['gst'] },
  { key: 'gstr2', label: 'GSTR-2 (Inward)', component: Gstr2Report, modules: ['gst'] },
  { key: 'tds-register', label: 'TDS Register', component: TdsRegisterReport, modules: ['gst'] },
  { key: 'tcs-register', label: 'TCS Register', component: TcsRegisterReport, modules: ['gst'] },
  { key: 'stock-ageing', label: 'Stock Ageing', component: StockAgeingReport, modules: ['inventory'] },
  { key: 'slow-moving', label: 'Slow Moving Stock', component: SlowMovingReport, modules: ['inventory'] },
  { key: 'fast-moving', label: 'Fast Moving Stock', component: FastMovingReport, modules: ['inventory'] },
  { key: 'manufacturing', label: 'Manufacturing', component: ManufacturingReport, modules: ['manufacturing'] },
  { key: 'production-cost', label: 'Production Cost', component: ProductionCostReport, modules: ['manufacturing'] },
  { key: 'tailor', label: 'Tailor', component: TailorReport, modules: ['tailoring'] },
  { key: 'embroidery', label: 'Embroidery', component: EmbroideryReport, modules: ['embroidery'] },
  { key: 'printing', label: 'Printing', component: PrintingReport, modules: ['manufacturing'] },
  { key: 'expenses', label: 'Expenses', component: ExpenseReport, modules: ['accounts'] },
  { key: 'customer-ledger', label: 'Customer Ledger', component: CustomerLedgerReport, modules: ['accounts'] },
  { key: 'supplier-ledger', label: 'Supplier Ledger', component: SupplierLedgerReport, modules: ['accounts'] },
  // Import & Export Logistics registers — the exportable date-range reports over
  // the same shipments the module's own boards work from.
  { key: 'import-shipments', label: 'Import Report', component: ImportShipmentReport, modules: ['logistics'] },
  { key: 'export-shipments', label: 'Export Report', component: ExportShipmentReport, modules: ['logistics'] },
  { key: 'shipment-profit', label: 'Shipment Profit', component: ShipmentProfitReport, modules: ['logistics'] },
  { key: 'shipment-expenses', label: 'Shipment Expense', component: ShipmentExpenseReport, modules: ['logistics'] },
  { key: 'shipment-customs', label: 'Customs Report', component: ShipmentCustomsReport, modules: ['logistics'] },
  { key: 'shipment-containers', label: 'Container Report', component: ShipmentContainerReport, modules: ['logistics'] },
  { key: 'country-wise-sales', label: 'Country-wise Sales', component: CountryWiseSalesReport, modules: ['logistics'] },
  { key: 'port-wise-shipments', label: 'Port-wise Shipment', component: PortWiseShipmentReport, modules: ['logistics'] },
]

const route = useRoute()
const auth = useAuthStore()

// Only the reports whose module the workspace switched on. The sidebar already
// hides the rest, but /reports/<key> is also reachable by URL, bookmark, favorite
// or a recent — and the API answers 403 for those, so render an explanation
// instead of a report stuck on an error.
const available = computed(() => reports.filter((r) => auth.hasModule(...r.modules)))

const requested = computed(() => (route.params.tab ? String(route.params.tab) : null))

const activeReport = computed(() => {
  if (!requested.value) return available.value[0] ?? null

  return available.value.find((r) => r.key === requested.value) ?? null
})

// A real report that this workspace's modules don't cover (as opposed to a typo'd
// key) — worth naming the module so the message is actionable.
const gatedReport = computed(() =>
  activeReport.value ? null : (reports.find((r) => r.key === requested.value) ?? null),
)

const moduleLabels: Record<string, string> = {
  sales: 'Sales',
  purchase: 'Purchase',
  inventory: 'Inventory',
  broker: 'Broker',
  gst: 'GST & Compliance',
  accounts: 'Accounts',
  manufacturing: 'Manufacturing',
  tailoring: 'Tailoring',
  embroidery: 'Embroidery',
  logistics: 'Logistics',
}

const gatedModules = computed(() =>
  (gatedReport.value?.modules ?? []).map((m) => moduleLabels[m] ?? m).join(' or '),
)
</script>

<template>
  <div>
    <PageHeader
      :title="activeReport?.label ?? gatedReport?.label ?? 'Reports'"
      subtitle="Reports · CSV / Excel / PDF export"
    />

    <component :is="activeReport.component" v-if="activeReport" />

    <div v-else class="report-unavailable">
      <i class="pi pi-lock" />
      <p v-if="gatedReport">
        The <strong>{{ gatedModules }}</strong> module is not enabled for this workspace, so
        <strong>{{ gatedReport.label }}</strong> is unavailable.
      </p>
      <p v-else-if="requested">This report does not exist.</p>
      <p v-else>No reports are available for the modules enabled on this workspace.</p>
      <small>An administrator can change the workspace's modules from the subscription plan.</small>
    </div>
  </div>
</template>

<style scoped>
h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.subtitle {
  color: #6b7280;
  margin-bottom: 1.25rem;
}
.report-unavailable {
  background: var(--surface-card, #fff);
  border: 1px solid var(--surface-border, #e5e7eb);
  border-radius: 10px;
  padding: 2.5rem 1.5rem;
  text-align: center;
  color: var(--text-color-secondary, #6b7280);
}
.report-unavailable i {
  font-size: 1.75rem;
  display: block;
  margin-bottom: 0.75rem;
  opacity: 0.6;
}
.report-unavailable p {
  margin: 0 0 0.35rem;
  color: var(--text-color, #374151);
}
</style>
