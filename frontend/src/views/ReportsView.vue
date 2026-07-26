<script setup lang="ts">
import { computed, type Component } from 'vue'
import { useRoute } from 'vue-router'
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
import PageHeader from '../components/PageHeader.vue'

interface ReportItem {
  key: string
  label: string
  component: Component
}

// Keys must match the sidebar's "Reports" submenu routes (/reports/<key>) in AppLayout.vue.
const reports: ReportItem[] = [
  { key: 'stock-overview', label: 'Stock Overview', component: StockOverviewReport },
  { key: 'stock-movement', label: 'Stock Movement', component: StockMovementReport },
  { key: 'fast-slow', label: 'Fast / Slow Moving', component: FastSlowMovingReport },
  { key: 'purchases', label: 'Purchases', component: PurchaseReport },
  { key: 'vendor-performance', label: 'Vendor Performance', component: VendorPerformanceReport },
  { key: 'payables', label: 'Payables / Pending Payments', component: PayablesReport },
  { key: 'sales', label: 'Sales', component: SalesReport },
  { key: 'broker-commissions', label: 'Broker Commissions', component: BrokerCommissionReport },
  { key: 'broker-wise-sales', label: 'Broker-wise Sales', component: BrokerWiseSalesReport },
  { key: 'profitability', label: 'Profitability', component: ProfitabilityReport },
  { key: 'valuation', label: 'Inventory Valuation', component: ValuationReport },
  { key: 'gst', label: 'GST / Tax', component: GstReport },
  { key: 'gst-summary', label: 'GST Summary (GSTR-3B)', component: GstSummaryReport },
  { key: 'gstr1', label: 'GSTR-1 (Outward)', component: Gstr1Report },
  { key: 'gstr2', label: 'GSTR-2 (Inward)', component: Gstr2Report },
  { key: 'tds-register', label: 'TDS Register', component: TdsRegisterReport },
  { key: 'tcs-register', label: 'TCS Register', component: TcsRegisterReport },
  { key: 'stock-ageing', label: 'Stock Ageing', component: StockAgeingReport },
  { key: 'slow-moving', label: 'Slow Moving Stock', component: SlowMovingReport },
  { key: 'fast-moving', label: 'Fast Moving Stock', component: FastMovingReport },
  { key: 'manufacturing', label: 'Manufacturing', component: ManufacturingReport },
  { key: 'production-cost', label: 'Production Cost', component: ProductionCostReport },
  { key: 'tailor', label: 'Tailor', component: TailorReport },
  { key: 'embroidery', label: 'Embroidery', component: EmbroideryReport },
  { key: 'printing', label: 'Printing', component: PrintingReport },
  { key: 'expenses', label: 'Expenses', component: ExpenseReport },
  { key: 'customer-ledger', label: 'Customer Ledger', component: CustomerLedgerReport },
  { key: 'supplier-ledger', label: 'Supplier Ledger', component: SupplierLedgerReport },
]

const route = useRoute()
const activeReport = computed(() => reports.find((r) => r.key === route.params.tab) ?? reports[0])
</script>

<template>
  <div>
    <PageHeader :title="activeReport.label" subtitle="Reports · CSV / Excel / PDF export" />

    <component :is="activeReport.component" />
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
</style>
