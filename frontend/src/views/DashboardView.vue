<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import Chart from 'primevue/chart'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import GettingStarted from '../components/GettingStarted.vue'

interface TopProduct { sku: string; name: string; qty_sold: number }
interface Activity { description: string; event: string | null; log_name: string | null; causer: string | null; at: string | null }

interface Kpis {
  today_sales: { total: number; count: number }
  purchase_summary: { month_total: number; month_count: number; pending_count: number }
  pending_orders: { sales: number; purchases: number; total: number }
  low_stock_count: number
  out_of_stock_count: number
  profit_loss: { revenue: number; cogs: number; gross_profit: number; period: string }
  total_stock_value: number
  products_count: number
  top_products: TopProduct[]
  recent_activities: Activity[]
}

interface WidgetState { key: string; visible: boolean }

// Widget metadata: title + which layout column type it occupies. Stat tiles sit
// in the top grid; panels take a full-width row.
const WIDGET_META: Record<string, { title: string; kind: 'stat' | 'panel' }> = {
  today_sales: { title: "Today's Sales", kind: 'stat' },
  purchase_summary: { title: 'Purchases (this month)', kind: 'stat' },
  pending_orders: { title: 'Pending Orders', kind: 'stat' },
  profit_loss: { title: 'Gross Profit', kind: 'stat' },
  low_stock: { title: 'Low Stock Alert', kind: 'stat' },
  out_of_stock: { title: 'Out of Stock', kind: 'stat' },
  total_stock_value: { title: 'Stock Value', kind: 'stat' },
  products_count: { title: 'Products', kind: 'stat' },
  top_products: { title: 'Top Selling Products', kind: 'panel' },
  recent_activities: { title: 'Recent Activities', kind: 'panel' },
}

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
  top_products: [],
  recent_activities: [],
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
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

const chartData = computed(() => ({
  labels: kpis.value.top_products.map((s) => s.sku),
  datasets: [{ label: 'Qty Sold (30d)', backgroundColor: '#e8a33d', data: kpis.value.top_products.map((s) => s.qty_sold) }],
}))
const chartOptions = { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }

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
    toast.add({ severity: 'success', summary: 'Dashboard saved', life: 2500 })
  } catch {
    toast.add({ severity: 'error', summary: 'Could not save layout', life: 3500 })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="dashboard">
    <div class="dash-head">
      <div>
        <h1>Dashboard</h1>
        <p class="subtitle">Overview of your business</p>
      </div>
      <div class="dash-actions">
        <button v-if="!editing" class="btn ghost" @click="editing = true">
          <i class="pi pi-th-large" /> Customize
        </button>
        <template v-else>
          <button class="btn ghost" @click="editing = false">Cancel</button>
          <button class="btn primary" :disabled="saving" @click="saveLayout">
            <i class="pi pi-check" /> {{ saving ? 'Saving…' : 'Save layout' }}
          </button>
        </template>
      </div>
    </div>

    <GettingStarted />

    <!-- Edit mode: full ordered list with drag handles + visibility toggles -->
    <div v-if="editing" class="editor">
      <p class="editor-hint"><i class="pi pi-arrows-alt" /> Drag to reorder · toggle the eye to show/hide widgets.</p>
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
        <span class="editor-title">{{ WIDGET_META[w.key]?.title ?? w.key }}</span>
        <span class="editor-kind">{{ WIDGET_META[w.key]?.kind }}</span>
        <button class="eye" :title="w.visible ? 'Hide' : 'Show'" @click="toggleVisible(w.key)">
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
              <div class="stat-label">Today's Sales</div>
              <div class="stat-sub">{{ kpis.today_sales.count }} invoice(s)</div>
            </div>
          </template>
          <!-- Purchase Summary -->
          <template v-else-if="w.key === 'purchase_summary'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #3d637c, #2c4a5e)"><i class="pi pi-shopping-cart" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.purchase_summary.month_total) }}</div>
              <div class="stat-label">Purchases (this month)</div>
              <div class="stat-sub">{{ kpis.purchase_summary.pending_count }} pending PO(s)</div>
            </div>
          </template>
          <!-- Pending Orders -->
          <template v-else-if="w.key === 'pending_orders'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #eaad48, #c98730)"><i class="pi pi-clock" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.pending_orders.total }}</div>
              <div class="stat-label">Pending Orders</div>
              <div class="stat-sub">{{ kpis.pending_orders.sales }} sales · {{ kpis.pending_orders.purchases }} purchase</div>
            </div>
          </template>
          <!-- Profit & Loss -->
          <template v-else-if="w.key === 'profit_loss'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #4c8b6c, #2c4a5e)"><i class="pi pi-chart-line" /></div>
            <div class="stat-body">
              <div class="stat-value" :class="{ negative: kpis.profit_loss.gross_profit < 0 }">{{ money(kpis.profit_loss.gross_profit) }}</div>
              <div class="stat-label">Gross Profit ({{ kpis.profit_loss.period }})</div>
              <div class="stat-sub">Rev {{ money(kpis.profit_loss.revenue) }} · COGS {{ money(kpis.profit_loss.cogs) }}</div>
            </div>
          </template>
          <!-- Low Stock -->
          <template v-else-if="w.key === 'low_stock'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #d66a45, #c1502e)"><i class="pi pi-exclamation-triangle" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.low_stock_count }}</div>
              <div class="stat-label">Low Stock Alert</div>
              <div class="stat-sub">at/below reorder level</div>
            </div>
          </template>
          <!-- Out of Stock -->
          <template v-else-if="w.key === 'out_of_stock'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #b3402b, #8f2f1f)"><i class="pi pi-times-circle" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.out_of_stock_count }}</div>
              <div class="stat-label">Out of Stock</div>
              <div class="stat-sub">products with no stock</div>
            </div>
          </template>
          <!-- Total Stock Value -->
          <template v-else-if="w.key === 'total_stock_value'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #6b7280, #374151)"><i class="pi pi-database" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ money(kpis.total_stock_value) }}</div>
              <div class="stat-label">Stock Value</div>
              <div class="stat-sub">on-hand valuation</div>
            </div>
          </template>
          <!-- Products Count -->
          <template v-else-if="w.key === 'products_count'">
            <div class="stat-icon" style="background: linear-gradient(135deg, #8a5cf6, #6d28d9)"><i class="pi pi-box" /></div>
            <div class="stat-body">
              <div class="stat-value">{{ kpis.products_count }}</div>
              <div class="stat-label">Products</div>
              <div class="stat-sub">active catalogue</div>
            </div>
          </template>
        </div>
      </div>

      <div class="panel-row">
        <div v-for="w in visiblePanels" :key="w.key" class="panel">
          <!-- Top Selling Products -->
          <template v-if="w.key === 'top_products'">
            <h2>Top Selling Products <small>(last 30 days)</small></h2>
            <table class="mini-table" v-if="kpis.top_products.length">
              <thead><tr><th>SKU</th><th>Name</th><th class="num">Qty Sold</th></tr></thead>
              <tbody>
                <tr v-for="s in kpis.top_products" :key="s.sku">
                  <td>{{ s.sku }}</td>
                  <td>{{ s.name }}</td>
                  <td class="num">{{ s.qty_sold }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="empty">No sales activity in the last 30 days.</p>
            <Chart v-if="kpis.top_products.length" type="bar" :data="chartData" :options="chartOptions" class="chart" />
          </template>
          <!-- Recent Activities -->
          <template v-else-if="w.key === 'recent_activities'">
            <h2>Recent Activities</h2>
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
            <p v-else class="empty">No recent activity yet.</p>
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
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.stat-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e8e4da);
  border-radius: 12px;
  padding: 1.25rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  transition: box-shadow 0.15s, transform 0.15s;
}
.stat-card:hover {
  box-shadow: 0 6px 20px rgba(30, 35, 40, 0.08);
  transform: translateY(-1px);
}
.stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 1.25rem;
  box-shadow: 0 4px 12px rgba(30, 35, 40, 0.18);
  flex-shrink: 0;
}
.stat-value {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--graphite, #1e2328);
}
.stat-value.negative {
  color: #c1502e;
}
.stat-label {
  color: var(--graphite-light, #374151);
  font-size: 0.85rem;
  font-weight: 500;
}
.stat-sub {
  color: #9ca3af;
  font-size: 0.75rem;
  margin-top: 0.1rem;
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
  max-height: 240px;
  margin-top: 1rem;
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
