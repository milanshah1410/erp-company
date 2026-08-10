<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Chart from 'primevue/chart'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import GettingStarted from '../components/GettingStarted.vue'

interface TopProduct { sku: string; name: string; qty_sold: number }
interface Activity { description: string; event: string | null; log_name: string | null; causer: string | null; at: string | null }
interface TrendPoint { date: string; label: string; total: number; count: number }

interface Kpis {
  today_sales: { total: number; count: number }
  purchase_summary: { month_total: number; month_count: number; pending_count: number }
  pending_orders: { sales: number; purchases: number; total: number }
  low_stock_count: number
  out_of_stock_count: number
  profit_loss: { revenue: number; cogs: number; gross_profit: number; period: string }
  total_stock_value: number
  products_count: number
  // Customer satisfaction, last 30 days (retail base §1, §6). `csat_percent` and
  // `average_rating` are null until somebody has answered.
  customer_csat: {
    responses: number
    satisfied: number
    detractors: number
    average_rating: number | null
    csat_percent: number | null
    unanswered_complaints: number
  }
  // Factory floor (_base-factory.md §1). `qc_rejection.percent` is null until
  // something has been inspected — a works that ran no inspections has no rate.
  production_wip: { orders: number; quantity: number; value: number }
  production_today: { quantity: number; orders_completed: number }
  machine_downtime: { minutes: number; hours: number; machines_down: number; machines_active: number; period: string }
  qc_rejection: { percent: number | null; inspected: number; rejected: number; period: string }
  scrap_value: { value: number; recoverable: number; net: number; period: string }
  job_work_outstanding: { count: number; overdue: number }
  orders_in_hand: { count: number; value: number }
  receivables: { total: number; overdue: number }
  payables: { total: number; overdue: number }
  top_products: TopProduct[]
  recent_activities: Activity[]
  sales_trend: TrendPoint[]
}

interface WidgetState { key: string; visible: boolean }

// Widget metadata: i18n title key + which layout column type it occupies.
// Stat tiles sit in the top grid; panels take a full-width row.
const WIDGET_META: Record<string, { titleKey: string; kind: 'stat' | 'panel' }> = {
  today_sales: { titleKey: 'dashboard.todaySales', kind: 'stat' },
  purchase_summary: { titleKey: 'dashboard.purchasesMonth', kind: 'stat' },
  pending_orders: { titleKey: 'dashboard.pendingOrders', kind: 'stat' },
  profit_loss: { titleKey: 'dashboard.grossProfit', kind: 'stat' },
  low_stock: { titleKey: 'dashboard.lowStock', kind: 'stat' },
  out_of_stock: { titleKey: 'dashboard.outOfStock', kind: 'stat' },
  total_stock_value: { titleKey: 'dashboard.stockValue', kind: 'stat' },
  products_count: { titleKey: 'dashboard.products', kind: 'stat' },
  customer_csat: { titleKey: 'dashboard.csat', kind: 'stat' },
  // Factory floor (_base-factory.md §1). All stat tiles: a works wants nine
  // numbers at a glance, not nine panels to scroll past.
  production_wip: { titleKey: 'dashboard.wip', kind: 'stat' },
  production_today: { titleKey: 'dashboard.producedToday', kind: 'stat' },
  machine_downtime: { titleKey: 'dashboard.machineDowntime', kind: 'stat' },
  qc_rejection: { titleKey: 'dashboard.qcRejection', kind: 'stat' },
  scrap_value: { titleKey: 'dashboard.scrapValue', kind: 'stat' },
  job_work_outstanding: { titleKey: 'dashboard.jobWorkOut', kind: 'stat' },
  orders_in_hand: { titleKey: 'dashboard.ordersInHand', kind: 'stat' },
  receivables: { titleKey: 'dashboard.receivables', kind: 'stat' },
  payables: { titleKey: 'dashboard.payables', kind: 'stat' },
  top_products: { titleKey: 'dashboard.topProducts', kind: 'panel' },
  recent_activities: { titleKey: 'dashboard.recentActivities', kind: 'panel' },
}

const { t } = useI18n()
const toast = useToast()
const kpis = ref<Kpis>({
  today_sales: { total: 0, count: 0 },
  purchase_summary: { month_total: 0, month_count: 0, pending_count: 0 },
  pending_orders: { sales: 0, purchases: 0, total: 0 },
  low_stock_count: 0,
  out_of_stock_count: 0,
  profit_loss: { revenue: 0, cogs: 0, gross_profit: 0, period: 'This month' },
  total_stock_value: 0,
  products_count: 0,
  customer_csat: { responses: 0, satisfied: 0, detractors: 0, average_rating: null, csat_percent: null, unanswered_complaints: 0 },
  production_wip: { orders: 0, quantity: 0, value: 0 },
  production_today: { quantity: 0, orders_completed: 0 },
  machine_downtime: { minutes: 0, hours: 0, machines_down: 0, machines_active: 0, period: '' },
  qc_rejection: { percent: null, inspected: 0, rejected: 0, period: '' },
  scrap_value: { value: 0, recoverable: 0, net: 0, period: '' },
  job_work_outstanding: { count: 0, overdue: 0 },
  orders_in_hand: { count: 0, value: 0 },
  receivables: { total: 0, overdue: 0 },
  payables: { total: 0, overdue: 0 },
  top_products: [],
  recent_activities: [],
  sales_trend: [],
})
const layout = ref<WidgetState[]>([])
const loading = ref(true)
const editing = ref(false)
const saving = ref(false)

// Ordered, visible widgets split into the two render zones.
const visibleStats = computed(() =>
  layout.value.filter((w) => w.visible && WIDGET_META[w.key]?.kind === 'stat'),
)
const visiblePanels = computed(() =>
  layout.value.filter((w) => w.visible && WIDGET_META[w.key]?.kind === 'panel'),
)

function money(v: number) {
  return formatCurrency(v, { maximumFractionDigits: 0 })
}

function timeAgo(iso: string | null) {
  if (!iso) return ''
  const diff = Date.now() - new Date(iso).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1) return t('dashboard.justNow')
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

const chartData = computed(() => ({
  labels: kpis.value.top_products.map((s) => s.sku),
  datasets: [{ label: 'Qty Sold (30d)', backgroundColor: '#e8a33d', borderRadius: 6, data: kpis.value.top_products.map((s) => s.qty_sold) }],
}))
const chartOptions = { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } }, maintainAspectRatio: false }

// --- Sales-trend line/area chart (last 14 days) ---
const trendChartData = computed(() => ({
  labels: kpis.value.sales_trend.map((d) => d.label),
  datasets: [
    {
      label: 'Sales',
      data: kpis.value.sales_trend.map((d) => d.total),
      borderColor: '#e8a33d',
      backgroundColor: (ctx: any) => {
        const { chart } = ctx
        const { ctx: c, chartArea } = chart
        if (!chartArea) return 'rgba(232, 163, 61, 0.15)'
        const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
        g.addColorStop(0, 'rgba(232, 163, 61, 0.35)')
        g.addColorStop(1, 'rgba(232, 163, 61, 0.02)')
        return g
      },
      fill: true,
      tension: 0.4,
      borderWidth: 2.5,
      pointRadius: 0,
      pointHoverRadius: 5,
      pointBackgroundColor: '#e8a33d',
    },
  ],
}))
const trendChartOptions = computed(() => ({
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (item: any) => `  ${money(item.parsed.y)}`,
      },
    },
  },
  scales: {
    x: { grid: { display: false }, ticks: { color: '#9ca3af', maxRotation: 0, autoSkipPadding: 12 } },
    y: {
      beginAtZero: true,
      grid: { color: 'rgba(148, 163, 184, 0.15)' },
      ticks: { color: '#9ca3af', callback: (v: number) => money(v) },
    },
  },
}))

// 14-day headline totals shown beside the trend chart.
const trendTotal = computed(() => kpis.value.sales_trend.reduce((s, d) => s + d.total, 0))
const trendCount = computed(() => kpis.value.sales_trend.reduce((s, d) => s + d.count, 0))

onMounted(async () => {
  try {
    const [kpiRes, layoutRes] = await Promise.all([
      http.get('/dashboard/kpis'),
      http.get('/dashboard/layout'),
    ])
    kpis.value = { ...kpis.value, ...kpiRes.data }
    layout.value = layoutRes.data.widgets
  } catch {
    /* best-effort; leave zeroed defaults */
  } finally {
    loading.value = false
  }
})

// --- Drag & drop reorder (native HTML5, no dependency) ---
const dragIndex = ref<number | null>(null)

function onDragStart(index: number) {
  dragIndex.value = index
}
function onDrop(targetIndex: number) {
  if (dragIndex.value === null || dragIndex.value === targetIndex) return
  const list = [...layout.value]
  const [moved] = list.splice(dragIndex.value, 1)
  list.splice(targetIndex, 0, moved)
  layout.value = list
  dragIndex.value = null
}
function toggleVisible(key: string) {
  const w = layout.value.find((x) => x.key === key)
  if (w) w.visible = !w.visible
}

async function saveLayout() {
  saving.value = true
  try {
    const { data } = await http.put('/dashboard/layout', { widgets: layout.value })
    layout.value = data.widgets
    editing.value = false
    toast.add({ severity: 'success', summary: t('dashboard.saved'), life: 2500 })
  } catch {
    toast.add({ severity: 'error', summary: t('dashboard.saveFailed'), life: 3500 })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="dashboard">
    <div class="dash-head">
      <div>
        <h1>{{ t('dashboard.title') }}</h1>
        <p class="subtitle">{{ t('dashboard.subtitle') }}</p>
      </div>
      <div class="dash-actions">
        <button v-if="!editing" class="btn ghost" @click="editing = true">
          <i class="pi pi-th-large" /> {{ t('dashboard.customize') }}
        </button>
        <template v-else>
          <button class="btn ghost" @click="editing = false">{{ t('common.cancel') }}</button>
          <button class="btn primary" :disabled="saving" @click="saveLayout">
            <i class="pi pi-check" /> {{ saving ? t('dashboard.saving') : t('dashboard.saveLayout') }}
          </button>
        </template>
      </div>
    </div>

    <GettingStarted />

    <!-- Edit mode: full ordered list with drag handles + visibility toggles -->
    <div v-if="editing" class="editor">
      <p class="editor-hint"><i class="pi pi-arrows-alt" /> {{ t('dashboard.editorHint') }}</p>
      <div
        v-for="(w, i) in layout"
        :key="w.key"
        class="editor-row"
        :class="{ hidden: !w.visible, dragging: dragIndex === i }"
        draggable="true"
        @dragstart="onDragStart(i)"
        @dragover.prevent
        @drop="onDrop(i)"
      >
        <i class="pi pi-bars handle" />
        <span class="editor-title">{{ WIDGET_META[w.key] ? t(WIDGET_META[w.key].titleKey) : w.key }}</span>
        <span class="editor-kind">{{ WIDGET_META[w.key]?.kind }}</span>
        <button class="eye" :title="w.visible ? t('dashboard.hide') : t('dashboard.show')" @click="toggleVisible(w.key)">
          <i :class="w.visible ? 'pi pi-eye' : 'pi pi-eye-slash'" />
        </button>
      </div>
    </div>

    <template v-else>
      <div class="stat-grid">
        <div v-for="w in visibleStats" :key="w.key" class="stat-card">
          <!-- Today's Sales -->
          <template v-if="w.key === 'today_sales'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #5fa483, #4c8b6c)"><i class="pi pi-indian-rupee" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.today_sales.total) }}</div>
              <div class="stat-label">{{ t('dashboard.todaySales') }}</div>
              <div class="stat-sub">{{ t('dashboard.invoices', { n: kpis.today_sales.count }) }}</div>
            </div>
          </template>
          <!-- Purchase Summary -->
          <template v-else-if="w.key === 'purchase_summary'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #3d637c, #2c4a5e)"><i class="pi pi-shopping-cart" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.purchase_summary.month_total) }}</div>
              <div class="stat-label">{{ t('dashboard.purchasesMonth') }}</div>
              <div class="stat-sub">{{ t('dashboard.pendingPos', { n: kpis.purchase_summary.pending_count }) }}</div>
            </div>
          </template>
          <!-- Pending Orders -->
          <template v-else-if="w.key === 'pending_orders'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #eaad48, #c98730)"><i class="pi pi-clock" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.pending_orders.total }}</div>
              <div class="stat-label">{{ t('dashboard.pendingOrders') }}</div>
              <div class="stat-sub">{{ t('dashboard.salesPurchaseSplit', { sales: kpis.pending_orders.sales, purchases: kpis.pending_orders.purchases }) }}</div>
            </div>
          </template>
          <!-- Profit & Loss -->
          <template v-else-if="w.key === 'profit_loss'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #4c8b6c, #2c4a5e)"><i class="pi pi-chart-line" /></div>
            <div class="stat-body">
              <div class="stat-value" :class="{ negative: kpis.profit_loss.gross_profit < 0 }">{{ money(kpis.profit_loss.gross_profit) }}</div>
              <div class="stat-label">{{ t('dashboard.grossProfit') }} ({{ kpis.profit_loss.period }})</div>
              <div class="stat-sub">Rev {{ money(kpis.profit_loss.revenue) }} · COGS {{ money(kpis.profit_loss.cogs) }}</div>
            </div>
          </template>
          <!-- Low Stock -->
          <template v-else-if="w.key === 'low_stock'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #d66a45, #c1502e)"><i class="pi pi-exclamation-triangle" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.low_stock_count }}</div>
              <div class="stat-label">{{ t('dashboard.lowStock') }}</div>
              <div class="stat-sub">{{ t('dashboard.atBelowReorder') }}</div>
            </div>
          </template>
          <!-- Out of Stock -->
          <template v-else-if="w.key === 'out_of_stock'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #b3402b, #8f2f1f)"><i class="pi pi-times-circle" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.out_of_stock_count }}</div>
              <div class="stat-label">{{ t('dashboard.outOfStock') }}</div>
              <div class="stat-sub">{{ t('dashboard.noStockProducts') }}</div>
            </div>
          </template>
          <!-- Total Stock Value -->
          <template v-else-if="w.key === 'total_stock_value'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #6b7280, #374151)"><i class="pi pi-database" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.total_stock_value) }}</div>
              <div class="stat-label">{{ t('dashboard.stockValue') }}</div>
              <div class="stat-sub">{{ t('dashboard.onHandValuation') }}</div>
            </div>
          </template>
          <!-- Products Count -->
          <template v-else-if="w.key === 'products_count'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #8a5cf6, #6d28d9)"><i class="pi pi-box" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.products_count }}</div>
              <div class="stat-label">{{ t('dashboard.products') }}</div>
              <div class="stat-sub">{{ t('dashboard.activeCatalogue') }}</div>
            </div>
          </template>
          <!--
            Customer satisfaction (retail base §1, §6). Shows a dash, not 0%,
            until somebody has answered — a shop with no feedback yet is not a
            shop scoring zero, and the tile must not say so.

            The sub-line switches to the complaint count when there is one
            outstanding, because that is the actionable half: a good score with
            three unanswered complaints under it is still three people waiting.
          -->
          <template v-else-if="w.key === 'customer_csat'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #4c8b6c, #2f6d52)"><i class="pi pi-comments" /></div>
            <div class="stat-body">
              <div class="stat-value">
                {{ kpis.customer_csat.csat_percent === null ? '—' : kpis.customer_csat.csat_percent + '%' }}
              </div>
              <div class="stat-label">{{ t('dashboard.csat') }}</div>
              <div v-if="kpis.customer_csat.unanswered_complaints" class="stat-sub negative">
                {{ t('dashboard.csatComplaints', { n: kpis.customer_csat.unanswered_complaints }) }}
              </div>
              <div v-else-if="kpis.customer_csat.responses" class="stat-sub">
                {{ t('dashboard.csatResponses', { n: kpis.customer_csat.responses, avg: kpis.customer_csat.average_rating }) }}
              </div>
              <div v-else class="stat-sub">{{ t('dashboard.csatNone') }}</div>
            </div>
          </template>

          <!--
            ============ Factory floor (_base-factory.md §1) ============
            Hidden by default on an existing layout, so a shop never grows nine
            tiles it did not ask for; a works switches them on once.
          -->

          <!-- Work in progress -->
          <template v-else-if="w.key === 'production_wip'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #4c6b8b, #2c4a5e)"><i class="pi pi-cog" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.production_wip.orders }}</div>
              <div class="stat-label">{{ t('dashboard.wip') }}</div>
              <div class="stat-sub">{{ t('dashboard.wipSub', { qty: kpis.production_wip.quantity, value: money(kpis.production_wip.value) }) }}</div>
            </div>
          </template>

          <!-- Today's production -->
          <template v-else-if="w.key === 'production_today'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #4c8b6c, #2f6d52)"><i class="pi pi-check-circle" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.production_today.quantity }}</div>
              <div class="stat-label">{{ t('dashboard.producedToday') }}</div>
              <div class="stat-sub">{{ t('dashboard.ordersCompleted', { n: kpis.production_today.orders_completed }) }}</div>
            </div>
          </template>

          <!-- Machine downtime. Leads with machines down *now*, because that is
               the number somebody can still do something about today. -->
          <template v-else-if="w.key === 'machine_downtime'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #d66a45, #c1502e)"><i class="pi pi-ban" /></div>
            <div class="stat-body">
              <div class="stat-value" :class="{ negative: kpis.machine_downtime.machines_down > 0 }">
                {{ kpis.machine_downtime.machines_down }}/{{ kpis.machine_downtime.machines_active }}
              </div>
              <div class="stat-label">{{ t('dashboard.machineDowntime') }}</div>
              <div class="stat-sub">{{ t('dashboard.downtimeHours', { h: kpis.machine_downtime.hours }) }}</div>
            </div>
          </template>

          <!-- QC rejection. A dash, not 0%, when nothing was inspected — no
               inspections is not a perfect month. -->
          <template v-else-if="w.key === 'qc_rejection'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #b3402b, #8f2f1f)"><i class="pi pi-verified" /></div>
            <div class="stat-body">
              <div class="stat-value" :class="{ negative: (kpis.qc_rejection.percent ?? 0) > 5 }">
                {{ kpis.qc_rejection.percent === null ? '—' : kpis.qc_rejection.percent + '%' }}
              </div>
              <div class="stat-label">{{ t('dashboard.qcRejection') }}</div>
              <div v-if="kpis.qc_rejection.inspected" class="stat-sub">
                {{ t('dashboard.qcInspected', { n: kpis.qc_rejection.inspected }) }}
              </div>
              <div v-else class="stat-sub">{{ t('dashboard.qcNone') }}</div>
            </div>
          </template>

          <!-- Scrap. Shows what it cost net of what can be sold back. -->
          <template v-else-if="w.key === 'scrap_value'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #9a6b3f, #7a5230)"><i class="pi pi-trash" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.scrap_value.net) }}</div>
              <div class="stat-label">{{ t('dashboard.scrapValue') }} ({{ kpis.scrap_value.period }})</div>
              <div class="stat-sub">{{ t('dashboard.scrapSub', { gross: money(kpis.scrap_value.value), rec: money(kpis.scrap_value.recoverable) }) }}</div>
            </div>
          </template>

          <!-- Job work at vendors -->
          <template v-else-if="w.key === 'job_work_outstanding'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #7a5ea8, #5b4483)"><i class="pi pi-send" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.job_work_outstanding.count }}</div>
              <div class="stat-label">{{ t('dashboard.jobWorkOut') }}</div>
              <div class="stat-sub" :class="{ negative: kpis.job_work_outstanding.overdue > 0 }">
                {{ t('dashboard.jobWorkOverdue', { n: kpis.job_work_outstanding.overdue }) }}
              </div>
            </div>
          </template>

          <!-- Orders in hand -->
          <template v-else-if="w.key === 'orders_in_hand'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #2f6d52, #1f5240)"><i class="pi pi-inbox" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.orders_in_hand.value) }}</div>
              <div class="stat-label">{{ t('dashboard.ordersInHand') }}</div>
              <div class="stat-sub">{{ t('dashboard.openOrders', { n: kpis.orders_in_hand.count }) }}</div>
            </div>
          </template>

          <!-- Receivables -->
          <template v-else-if="w.key === 'receivables'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #4c8b6c, #2f6d52)"><i class="pi pi-arrow-down-left" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.receivables.total) }}</div>
              <div class="stat-label">{{ t('dashboard.receivables') }}</div>
              <div class="stat-sub" :class="{ negative: kpis.receivables.overdue > 0 }">
                {{ t('dashboard.overdueAmount', { amt: money(kpis.receivables.overdue) }) }}
              </div>
            </div>
          </template>

          <!-- Payables -->
          <template v-else-if="w.key === 'payables'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #c1502e, #8f2f1f)"><i class="pi pi-arrow-up-right" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.payables.total) }}</div>
              <div class="stat-label">{{ t('dashboard.payables') }}</div>
              <div class="stat-sub" :class="{ negative: kpis.payables.overdue > 0 }">
                {{ t('dashboard.overdueAmount', { amt: money(kpis.payables.overdue) }) }}
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Sales trend — always-on hero chart so the dashboard never looks empty. -->
      <div class="trend-card">
        <div class="trend-head">
          <div>
            <h2>{{ t('dashboard.salesTrend') }} <small>{{ t('dashboard.last14Days') }}</small></h2>
            <div class="trend-total">{{ money(trendTotal) }}</div>
            <div class="trend-sub">{{ t('dashboard.invoices', { n: trendCount }) }} · {{ t('dashboard.today') }} {{ money(kpis.today_sales.total) }}</div>
          </div>
          <div class="trend-badge"><i class="pi pi-chart-line" /></div>
        </div>
        <div class="trend-chart-wrap">
          <Chart type="line" :data="trendChartData" :options="trendChartOptions" class="trend-chart" />
        </div>
      </div>

      <div class="panel-row">
        <div v-for="w in visiblePanels" :key="w.key" class="panel">
          <!-- Top Selling Products -->
          <template v-if="w.key === 'top_products'">
            <h2>{{ t('dashboard.topProducts') }} <small>{{ t('dashboard.last30Days') }}</small></h2>
            <table class="mini-table" v-if="kpis.top_products.length">
              <thead><tr><th>{{ t('dashboard.sku') }}</th><th>{{ t('common.name') }}</th><th class="num">{{ t('dashboard.qtySold') }}</th></tr></thead>
              <tbody>
                <tr v-for="s in kpis.top_products" :key="s.sku">
                  <td>{{ s.sku }}</td>
                  <td>{{ s.name }}</td>
                  <td class="num">{{ s.qty_sold }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="empty">{{ t('dashboard.noSales30') }}</p>
            <Chart v-if="kpis.top_products.length" type="bar" :data="chartData" :options="chartOptions" class="chart" />
          </template>
          <!-- Recent Activities -->
          <template v-else-if="w.key === 'recent_activities'">
            <h2>{{ t('dashboard.recentActivities') }}</h2>
            <ul class="activity" v-if="kpis.recent_activities.length">
              <li v-for="(a, i) in kpis.recent_activities" :key="i">
                <span class="dot" />
                <span class="act-body">
                  <span class="act-desc">{{ a.description }}</span>
                  <span class="act-meta">
                    <template v-if="a.causer">{{ a.causer }} · </template>{{ a.log_name }}
                  </span>
                </span>
                <span class="act-time">{{ timeAgo(a.at) }}</span>
              </li>
            </ul>
            <p v-else class="empty">{{ t('dashboard.noActivity') }}</p>
          </template>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.dash-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}
.dashboard h1 {
  font-size: 1.6rem;
  font-weight: 600;
}
.dashboard h2 {
  font-size: 1.1rem;
  font-weight: 600;
  margin: 0 0 0.75rem;
}
.dashboard h2 small {
  font-weight: 400;
  color: #9ca3af;
  font-size: 0.8rem;
}
.subtitle {
  color: #6b7280;
  margin-bottom: 1.5rem;
}
.dash-actions {
  display: flex;
  gap: 0.5rem;
  flex-shrink: 0;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border-radius: 8px;
  padding: 0.5rem 0.9rem;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
}
.btn.ghost {
  background: var(--surface, #fff);
  border-color: var(--border, #e3ded3);
  color: var(--graphite, #1e2328);
}
.btn.primary {
  background: var(--safety-amber, #e8a33d);
  color: #23292f;
}
.btn:disabled {
  opacity: 0.6;
  cursor: default;
}
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1.4rem;
  margin-bottom: 1.75rem;
}
.stat-card {
  position: relative;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 18px;
  padding: 2.1rem 1.9rem;
  min-height: 130px;
  display: flex;
  align-items: center;
  gap: 1.5rem;
  overflow: hidden;
  transition: box-shadow 0.18s, transform 0.18s, border-color 0.18s;
}
.stat-card::after {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 4px;
  background: var(--safety-amber, #e8a33d);
  opacity: 0.85;
}
.stat-card:hover {
  box-shadow: 0 12px 32px rgba(30, 35, 40, 0.12);
  transform: translateY(-3px);
  border-color: rgba(232, 163, 61, 0.4);
}
.stat-icon {
  width: 88px;
  height: 88px;
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 2.75rem;
  box-shadow: 0 6px 16px rgba(30, 35, 40, 0.22);
  flex-shrink: 0;
}
.stat-icon i {
  font-size: inherit;
}
.stat-value {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 2.5rem;
  line-height: 1.1;
  font-weight: 700;
  color: var(--graphite, #1e2328);
}
.stat-value.negative {
  color: #c1502e;
}
.stat-label {
  color: var(--graphite-light, #374151);
  font-size: 1.02rem;
  font-weight: 600;
  margin-top: 0.35rem;
}
.stat-sub {
  color: #9ca3af;
  font-size: 0.82rem;
  margin-top: 0.25rem;
}
.panel-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1rem;
}
.panel {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1.25rem;
}
.mini-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.mini-table th {
  text-align: left;
  color: #6b7280;
  font-weight: 500;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--border, #e5e7eb);
}
.mini-table td {
  padding: 0.45rem 0;
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}
.mini-table .num {
  text-align: right;
}
.chart {
  height: 240px;
  margin-top: 1rem;
}

/* --- Sales-trend hero chart --- */
.trend-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 16px;
  padding: 1.5rem 1.6rem;
  margin-bottom: 1.75rem;
  box-shadow: 0 4px 18px rgba(30, 35, 40, 0.05);
}
.trend-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}
.trend-total {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 2.3rem;
  font-weight: 700;
  color: var(--graphite, #1e2328);
  line-height: 1.1;
  margin-top: 0.35rem;
}
.trend-sub {
  color: #9ca3af;
  font-size: 0.82rem;
  margin-top: 0.25rem;
}
.trend-badge {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 1.4rem;
  background: linear-gradient(135deg, #e8a33d, #c98730);
  box-shadow: 0 6px 16px rgba(232, 163, 61, 0.35);
  flex-shrink: 0;
}
.trend-chart-wrap {
  height: 260px;
  margin-top: 1rem;
}
.trend-chart {
  height: 260px;
}
.activity {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}
.activity li {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}
.activity li:last-child {
  border-bottom: none;
}
.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #e8a33d;
  margin-top: 0.4rem;
  flex-shrink: 0;
}
.act-body {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.act-desc {
  font-size: 0.88rem;
  color: var(--graphite, #1e2328);
  text-transform: capitalize;
}
.act-meta {
  font-size: 0.75rem;
  color: #9ca3af;
}
.act-time {
  font-size: 0.75rem;
  color: #9ca3af;
  white-space: nowrap;
}
.empty {
  color: #6b7280;
  font-size: 0.9rem;
}

/* --- Customize / edit mode --- */
.editor {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1rem 1.25rem;
}
.editor-hint {
  color: #6b7280;
  font-size: 0.85rem;
  margin: 0 0 0.75rem;
}
.editor-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid var(--border, #e8e4da);
  border-radius: 8px;
  margin-bottom: 0.5rem;
  background: var(--surface, #fff);
  cursor: grab;
}
.editor-row.dragging {
  opacity: 0.5;
}
.editor-row.hidden {
  opacity: 0.55;
}
.handle {
  color: #9ca3af;
}
.editor-title {
  flex: 1;
  font-weight: 500;
  color: var(--graphite, #1e2328);
}
.editor-kind {
  font-size: 0.72rem;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.eye {
  background: none;
  border: none;
  cursor: pointer;
  color: #6b7280;
  padding: 0.35rem;
  border-radius: 6px;
}
.eye:hover {
  color: var(--safety-amber, #e8a33d);
}
</style>
