<script setup lang="ts">
import { computed, type Component } from 'vue'
import { useRoute } from 'vue-router'
import StockOverviewReport from '../components/reports/StockOverviewReport.vue'
import StockMovementReport from '../components/reports/StockMovementReport.vue'
import FastSlowMovingReport from '../components/reports/FastSlowMovingReport.vue'
import ValuationReport from '../components/reports/ValuationReport.vue'
import PurchaseReport from '../components/reports/PurchaseReport.vue'
import VendorPerformanceReport from '../components/reports/VendorPerformanceReport.vue'
import SalesReport from '../components/reports/SalesReport.vue'
import ProfitabilityReport from '../components/reports/ProfitabilityReport.vue'
import GstReport from '../components/reports/GstReport.vue'

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
  { key: 'sales', label: 'Sales', component: SalesReport },
  { key: 'profitability', label: 'Profitability', component: ProfitabilityReport },
  { key: 'valuation', label: 'Inventory Valuation', component: ValuationReport },
  { key: 'gst', label: 'GST / Tax', component: GstReport },
]

const route = useRoute()
const activeReport = computed(() => reports.find((r) => r.key === route.params.tab) ?? reports[0])
</script>

<template>
  <div>
    <h1>{{ activeReport.label }}</h1>
    <p class="subtitle">Reports · CSV / Excel / PDF export</p>

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
