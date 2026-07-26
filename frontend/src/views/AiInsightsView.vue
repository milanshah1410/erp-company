<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Chart from 'primevue/chart'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import InputNumber from 'primevue/inputnumber'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import { useAuthStore } from '../stores/auth'
import PageHeader from '../components/PageHeader.vue'

/**
 * AI Insights (SRS §22): sales forecast, demand prediction, forecast-driven
 * auto purchase suggestions, low-stock (days-to-stockout) prediction, and the
 * narrated AI business report. Every panel is backed by the tenant's own data
 * through the /ai/* endpoints; the numbers are computed locally on the server.
 */

const toast = useToast()
const auth = useAuthStore()
const canCreatePo = computed(() => auth.can('purchases.create'))

type TabKey = 'forecast' | 'demand' | 'purchase' | 'lowstock' | 'report'
const tabs: { key: TabKey; label: string; icon: string }[] = [
  { key: 'forecast', label: 'Sales Forecast', icon: 'pi pi-chart-line' },
  { key: 'demand', label: 'Demand Prediction', icon: 'pi pi-box' },
  { key: 'purchase', label: 'Auto Purchase', icon: 'pi pi-shopping-cart' },
  { key: 'lowstock', label: 'Low Stock', icon: 'pi pi-exclamation-triangle' },
  { key: 'report', label: 'AI Report', icon: 'pi pi-sparkles' },
]
const activeTab = ref<TabKey>('forecast')
const loaded = ref<Record<TabKey, boolean>>({ forecast: false, demand: false, purchase: false, lowstock: false, report: false })
const loading = ref<Record<TabKey, boolean>>({ forecast: false, demand: false, purchase: false, lowstock: false, report: false })

function selectTab(key: TabKey) {
  activeTab.value = key
  if (!loaded.value[key]) load(key)
}

function money(v: number) {
  return formatCurrency(v, { maximumFractionDigits: 0 })
}

function toastError(e: any) {
  toast.add({ severity: 'error', summary: 'AI request failed', detail: e?.response?.data?.message ?? 'Please try again.', life: 4000 })
}

// -- Provider status ---------------------------------------------------------
const providerLabel = ref('')
async function loadStatus() {
  try {
    const { data } = await http.get('/ai/status')
    const p = data.data
    providerLabel.value = p.using_hosted_model ? `Hosted model (${p.active_provider})` : 'Local engine'
  } catch { /* non-fatal */ }
}

// -- Sales forecast ----------------------------------------------------------
interface ForecastPoint { period: string; label: string; value: number; lower?: number; upper?: number }
const forecast = ref<{ history: ForecastPoint[]; forecast: ForecastPoint[]; trend: string; growth_rate: number; confidence: number }>({
  history: [], forecast: [], trend: 'flat', growth_rate: 0, confidence: 0,
})
const forecastPeriods = ref(6)

async function loadForecast() {
  loading.value.forecast = true
  try {
    const { data } = await http.get('/ai/sales-forecast', { params: { periods: forecastPeriods.value } })
    forecast.value = data.data
    loaded.value.forecast = true
  } catch (e) { toastError(e) } finally { loading.value.forecast = false }
}

const forecastChart = computed(() => {
  const hist = forecast.value.history
  const fc = forecast.value.forecast
  const labels = [...hist.map((p) => p.label), ...fc.map((p) => p.label)]
  const histData = [...hist.map((p) => p.value), ...fc.map(() => null)]
  // Connect the line: repeat the last historical point as the forecast's first anchor.
  const fcData = [...hist.map(() => null as number | null)]
  if (hist.length) fcData[hist.length - 1] = hist[hist.length - 1].value
  fc.forEach((p) => fcData.push(p.value))
  const upper = [...hist.map(() => null as number | null), ...fc.map((p) => p.upper ?? null)]
  const lower = [...hist.map(() => null as number | null), ...fc.map((p) => p.lower ?? null)]
  return {
    labels,
    datasets: [
      { label: 'Actual', data: histData, borderColor: '#3d637c', backgroundColor: '#3d637c', tension: 0.3, spanGaps: false },
      { label: 'Forecast', data: fcData, borderColor: '#e8a33d', backgroundColor: '#e8a33d', borderDash: [6, 4], tension: 0.3, spanGaps: true },
      { label: 'Upper band', data: upper, borderColor: 'rgba(232,163,61,0.25)', backgroundColor: 'rgba(232,163,61,0.10)', fill: '+1', pointRadius: 0, tension: 0.3, spanGaps: true },
      { label: 'Lower band', data: lower, borderColor: 'rgba(232,163,61,0.25)', backgroundColor: 'rgba(232,163,61,0.10)', fill: false, pointRadius: 0, tension: 0.3, spanGaps: true },
    ],
  }
})
const forecastChartOptions = {
  maintainAspectRatio: false,
  plugins: { legend: { labels: { filter: (i: any) => i.text === 'Actual' || i.text === 'Forecast' } } },
  scales: { y: { beginAtZero: true } },
}
const trendSeverity = computed(() => ({ rising: 'success', falling: 'danger', flat: 'secondary' }[forecast.value.trend] ?? 'secondary'))

// -- Demand prediction -------------------------------------------------------
interface DemandRow { product: { id: number; sku: string; name: string }; forecast_next: number; avg_monthly: number; trend: string; confidence: number }
const demand = ref<DemandRow[]>([])
async function loadDemand() {
  loading.value.demand = true
  try {
    const { data } = await http.get('/ai/demand-prediction', { params: { limit: 50 } })
    demand.value = data.data
    loaded.value.demand = true
  } catch (e) { toastError(e) } finally { loading.value.demand = false }
}

// -- Purchase suggestions ----------------------------------------------------
interface PurchaseRow {
  product: { id: number; sku: string; name: string }
  on_hand: number; on_order: number; daily_rate: number; days_of_cover: number | null
  recommended_quantity: number; estimated_cost: number; urgency: string
  supplier: { id: number; name: string } | null
}
const purchase = ref<{ items: PurchaseRow[]; lead_time_days: number }>({ items: [], lead_time_days: 7 })
async function loadPurchase() {
  loading.value.purchase = true
  try {
    const { data } = await http.get('/ai/purchase-suggestions')
    purchase.value = data.data
    loaded.value.purchase = true
  } catch (e) { toastError(e) } finally { loading.value.purchase = false }
}
const purchaseTotal = computed(() => purchase.value.items.reduce((s, i) => s + i.estimated_cost, 0))

async function generatePos() {
  const res = await Swal.fire({
    title: 'Generate draft purchase orders?',
    text: 'This creates one draft PO per supplier from the reorder suggestions.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Generate',
    confirmButtonColor: '#e8a33d',
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post('/reorder/generate')
    toast.add({ severity: 'success', summary: 'Draft POs created', detail: `${data.created?.length ?? 0} purchase order(s) created.`, life: 4000 })
  } catch (e) { toastError(e) }
}

// -- Low stock prediction ----------------------------------------------------
interface LowStockRow { product: { sku: string; name: string }; on_hand: number; daily_rate: number; days_to_stockout: number; projected_stockout_date: string; risk: string }
const lowstock = ref<{ items: LowStockRow[]; window_days: number }>({ items: [], window_days: 90 })
async function loadLowStock() {
  loading.value.lowstock = true
  try {
    const { data } = await http.get('/ai/low-stock-prediction')
    lowstock.value = data.data
    loaded.value.lowstock = true
  } catch (e) { toastError(e) } finally { loading.value.lowstock = false }
}

// -- AI report ---------------------------------------------------------------
const report = ref<{ narrative: string; insights: string[]; metrics: any; provider: string; period: string } | null>(null)
async function loadReport() {
  loading.value.report = true
  try {
    const { data } = await http.get('/ai/report')
    report.value = data.data
    loaded.value.report = true
  } catch (e) { toastError(e) } finally { loading.value.report = false }
}

// -- Dispatch ----------------------------------------------------------------
function load(key: TabKey) {
  if (key === 'forecast') return loadForecast()
  if (key === 'demand') return loadDemand()
  if (key === 'purchase') return loadPurchase()
  if (key === 'lowstock') return loadLowStock()
  if (key === 'report') return loadReport()
}

const riskSeverity: Record<string, string> = { critical: 'danger', high: 'warn', medium: 'info', low: 'secondary' }
const trendSev: Record<string, string> = { rising: 'success', falling: 'danger', flat: 'secondary' }

onMounted(() => {
  loadStatus()
  load('forecast')
})
</script>

<template>
  <div class="ai-insights">
    <PageHeader title="AI Insights" subtitle="Forecasting &amp; predictions from your business data">
      <template #actions>
        <span v-if="providerLabel" class="provider-badge"><i class="pi pi-microchip-ai" /> {{ providerLabel }}</span>
      </template>
    </PageHeader>

    <div class="tabbar">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="tab"
        :class="{ active: activeTab === t.key }"
        @click="selectTab(t.key)"
      >
        <i :class="t.icon" /> {{ t.label }}
      </button>
    </div>

    <!-- Sales Forecast -->
    <section v-show="activeTab === 'forecast'" class="panel">
      <div class="panel-head">
        <h2>Monthly Sales Forecast</h2>
        <div class="controls">
          <label>Months ahead</label>
          <InputNumber v-model="forecastPeriods" :min="1" :max="24" showButtons buttonLayout="horizontal" :inputStyle="{ width: '3rem' }" />
          <Button label="Update" icon="pi pi-refresh" size="small" :loading="loading.forecast" @click="loadForecast" />
        </div>
      </div>
      <div class="stat-row">
        <div class="mini-stat">
          <span class="mini-label">Trend</span>
          <Tag :severity="trendSeverity" :value="forecast.trend" />
        </div>
        <div class="mini-stat">
          <span class="mini-label">Monthly growth</span>
          <span class="mini-value" :class="{ neg: forecast.growth_rate < 0 }">{{ forecast.growth_rate > 0 ? '+' : '' }}{{ forecast.growth_rate }}%</span>
        </div>
        <div class="mini-stat">
          <span class="mini-label">Confidence</span>
          <span class="mini-value">{{ Math.round(forecast.confidence * 100) }}%</span>
        </div>
        <div class="mini-stat" v-if="forecast.forecast.length">
          <span class="mini-label">Next month</span>
          <span class="mini-value">{{ money(forecast.forecast[0].value) }}</span>
        </div>
      </div>
      <div class="chart-wrap">
        <Chart type="line" :data="forecastChart" :options="forecastChartOptions" />
      </div>
    </section>

    <!-- Demand Prediction -->
    <section v-show="activeTab === 'demand'" class="panel">
      <h2>Per-Product Demand Prediction <small>(next month, units)</small></h2>
      <DataTable :value="demand" :loading="loading.demand" paginator :rows="15" removableSort stripedRows>
        <Column field="product.sku" header="SKU" sortable />
        <Column field="product.name" header="Product" sortable />
        <Column field="avg_monthly" header="Avg / month" sortable>
          <template #body="{ data }">{{ data.avg_monthly }}</template>
        </Column>
        <Column field="forecast_next" header="Forecast (next)" sortable>
          <template #body="{ data }"><strong>{{ data.forecast_next }}</strong></template>
        </Column>
        <Column field="trend" header="Trend" sortable>
          <template #body="{ data }"><Tag :severity="trendSev[data.trend]" :value="data.trend" /></template>
        </Column>
        <Column field="confidence" header="Confidence" sortable>
          <template #body="{ data }">{{ Math.round(data.confidence * 100) }}%</template>
        </Column>
        <template #empty><p class="empty">No sales history to predict demand from yet.</p></template>
      </DataTable>
    </section>

    <!-- Auto Purchase Suggestions -->
    <section v-show="activeTab === 'purchase'" class="panel">
      <div class="panel-head">
        <h2>Auto Purchase Suggestions <small>(lead time {{ purchase.lead_time_days }} days)</small></h2>
        <div class="controls">
          <span class="total-pill">Est. total: {{ money(purchaseTotal) }}</span>
          <Button v-if="canCreatePo && purchase.items.length" label="Generate draft POs" icon="pi pi-file-plus" size="small" @click="generatePos" />
        </div>
      </div>
      <DataTable :value="purchase.items" :loading="loading.purchase" paginator :rows="15" removableSort stripedRows>
        <Column field="product.sku" header="SKU" sortable />
        <Column field="product.name" header="Product" sortable />
        <Column field="on_hand" header="On hand" sortable />
        <Column field="on_order" header="On order" sortable />
        <Column field="days_of_cover" header="Days cover" sortable>
          <template #body="{ data }">{{ data.days_of_cover ?? '—' }}</template>
        </Column>
        <Column field="recommended_quantity" header="Order qty" sortable>
          <template #body="{ data }"><strong>{{ data.recommended_quantity }}</strong></template>
        </Column>
        <Column field="estimated_cost" header="Est. cost" sortable>
          <template #body="{ data }">{{ money(data.estimated_cost) }}</template>
        </Column>
        <Column field="supplier.name" header="Supplier">
          <template #body="{ data }"><span :class="{ muted: !data.supplier }">{{ data.supplier?.name ?? 'No preferred supplier' }}</span></template>
        </Column>
        <Column field="urgency" header="Urgency" sortable>
          <template #body="{ data }"><Tag :severity="riskSeverity[data.urgency]" :value="data.urgency" /></template>
        </Column>
        <template #empty><p class="empty">Nothing to reorder — stock is comfortable against recent demand.</p></template>
      </DataTable>
    </section>

    <!-- Low Stock Prediction -->
    <section v-show="activeTab === 'lowstock'" class="panel">
      <h2>Low Stock Prediction <small>(days to stockout, {{ lowstock.window_days }}-day velocity)</small></h2>
      <DataTable :value="lowstock.items" :loading="loading.lowstock" paginator :rows="15" removableSort stripedRows>
        <Column field="product.sku" header="SKU" sortable />
        <Column field="product.name" header="Product" sortable />
        <Column field="on_hand" header="On hand" sortable />
        <Column field="daily_rate" header="Units / day" sortable />
        <Column field="days_to_stockout" header="Days left" sortable>
          <template #body="{ data }"><strong>{{ data.days_to_stockout }}</strong></template>
        </Column>
        <Column field="projected_stockout_date" header="Stockout date" sortable />
        <Column field="risk" header="Risk" sortable>
          <template #body="{ data }"><Tag :severity="riskSeverity[data.risk]" :value="data.risk" /></template>
        </Column>
        <template #empty><p class="empty">No products at stockout risk based on recent sales velocity.</p></template>
      </DataTable>
    </section>

    <!-- AI Report -->
    <section v-show="activeTab === 'report'" class="panel">
      <div class="panel-head">
        <h2>AI Business Report</h2>
        <Button label="Regenerate" icon="pi pi-refresh" size="small" :loading="loading.report" @click="loadReport" />
      </div>
      <div v-if="report">
        <div class="narrative">{{ report.narrative }}</div>
        <h3>Key insights</h3>
        <ul class="insights">
          <li v-for="(ins, i) in report.insights" :key="i"><i class="pi pi-angle-right" /> {{ ins }}</li>
        </ul>
        <div class="metric-grid" v-if="report.metrics">
          <div class="metric"><span class="m-label">This month revenue</span><span class="m-value">{{ money(report.metrics.this_month_revenue) }}</span></div>
          <div class="metric"><span class="m-label">Outstanding</span><span class="m-value">{{ money(report.metrics.outstanding_receivables) }}</span></div>
          <div class="metric"><span class="m-label">Overdue</span><span class="m-value neg">{{ money(report.metrics.overdue_receivables) }}</span></div>
          <div class="metric"><span class="m-label">Next month forecast</span><span class="m-value">{{ money(report.metrics.next_month_forecast) }}</span></div>
          <div class="metric"><span class="m-label">Reorder items</span><span class="m-value">{{ report.metrics.purchase_suggestion_count }}</span></div>
          <div class="metric"><span class="m-label">Dead stock</span><span class="m-value">{{ report.metrics.dead_stock_count }}</span></div>
        </div>
      </div>
      <p v-else-if="!loading.report" class="empty">No report generated yet.</p>
    </section>
  </div>
</template>

<style scoped>
.ai-insights h1 { font-size: 1.6rem; font-weight: 600; }
.subtitle { color: #6b7280; margin: 0.25rem 0 0; }
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem; gap: 1rem; flex-wrap: wrap; }
.provider-badge {
  background: #f4efe6; border: 1px solid #e8e4da; color: #7a5b1e;
  border-radius: 999px; padding: 0.4rem 0.8rem; font-size: 0.8rem; font-weight: 500; white-space: nowrap;
}
.tabbar { display: flex; gap: 0.35rem; flex-wrap: wrap; margin-bottom: 1rem; }
.tab {
  border: 1px solid var(--border, #e8e4da); background: var(--surface, #fff); color: var(--graphite, #374151); cursor: pointer;
  padding: 0.5rem 0.9rem; border-radius: 8px; font-size: 0.88rem; font-weight: 500;
  display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.15s;
}
.tab:hover { border-color: #e8a33d; }
.tab.active { background: #e8a33d; border-color: #e8a33d; color: #fff; }
.panel { background: var(--surface, #fff); border: 1px solid var(--border, #e8e4da); border-radius: 12px; padding: 1.25rem; }
.panel h2 { font-size: 1.1rem; font-weight: 600; margin: 0 0 0.75rem; }
.panel h2 small, .panel h3 small { font-weight: 400; color: #9ca3af; font-size: 0.8rem; }
.panel h3 { font-size: 0.95rem; font-weight: 600; margin: 1.25rem 0 0.5rem; }
.panel-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
.controls { display: flex; align-items: center; gap: 0.6rem; }
.controls label { font-size: 0.82rem; color: #6b7280; }
.stat-row { display: flex; gap: 1.5rem; flex-wrap: wrap; margin: 0.5rem 0 1rem; }
.mini-stat { display: flex; flex-direction: column; gap: 0.25rem; }
.mini-label { font-size: 0.75rem; color: #9ca3af; }
.mini-value { font-size: 1.2rem; font-weight: 700; color: #1e2328; font-family: 'Space Grotesk', 'Inter', sans-serif; }
.mini-value.neg, .m-value.neg { color: #c1502e; }
.chart-wrap { height: 340px; }
.total-pill { background: #f4efe6; border-radius: 8px; padding: 0.35rem 0.7rem; font-size: 0.85rem; font-weight: 600; color: #7a5b1e; }
.muted { color: #b8b3a8; font-style: italic; }
.empty { color: #6b7280; font-size: 0.9rem; }
.narrative { white-space: pre-line; line-height: 1.6; color: #374151; background: #fbfaf8; border: 1px solid #f1ece1; border-radius: 10px; padding: 1rem 1.1rem; }
.insights { list-style: none; margin: 0; padding: 0; }
.insights li { display: flex; gap: 0.5rem; padding: 0.4rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; color: #374151; }
.insights li i { color: #e8a33d; margin-top: 0.15rem; }
.metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin-top: 1.25rem; }
.metric { border: 1px solid #e8e4da; border-radius: 10px; padding: 0.8rem 0.9rem; display: flex; flex-direction: column; gap: 0.3rem; }
.m-label { font-size: 0.75rem; color: #9ca3af; }
.m-value { font-size: 1.15rem; font-weight: 700; color: #1e2328; font-family: 'Space Grotesk', 'Inter', sans-serif; }
</style>
