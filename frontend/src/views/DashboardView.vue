<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import Chart from 'primevue/chart'
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
  top_products: TopProduct[]
  recent_activities: Activity[]
}

const kpis = ref<Kpis>({
  today_sales: { total: 0, count: 0 },
  purchase_summary: { month_total: 0, month_count: 0, pending_count: 0 },
  pending_orders: { sales: 0, purchases: 0, total: 0 },
  low_stock_count: 0,
  out_of_stock_count: 0,
  profit_loss: { revenue: 0, cogs: 0, gross_profit: 0, period: 'This month' },
  top_products: [],
  recent_activities: [],
})
const loading = ref(true)

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
    const { data } = await http.get('/dashboard/kpis')
    kpis.value = { ...kpis.value, ...data }
  } catch {
    /* best-effort; leave zeroed defaults */
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="dashboard">
    <h1>Dashboard</h1>
    <p class="subtitle">Overview of your business</p>

    <GettingStarted />

    <div class="stat-grid">
      <!-- 1. Today's Sales -->
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #5fa483, #4c8b6c)"><i class="pi pi-indian-rupee" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ money(kpis.today_sales.total) }}</div>
          <div class="stat-label">Today's Sales</div>
          <div class="stat-sub">{{ kpis.today_sales.count }} invoice(s)</div>
        </div>
      </div>

      <!-- 2. Purchase Summary -->
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #3d637c, #2c4a5e)"><i class="pi pi-shopping-cart" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ money(kpis.purchase_summary.month_total) }}</div>
          <div class="stat-label">Purchases (this month)</div>
          <div class="stat-sub">{{ kpis.purchase_summary.pending_count }} pending PO(s)</div>
        </div>
      </div>

      <!-- 3. Pending Orders -->
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #eaad48, #c98730)"><i class="pi pi-clock" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ kpis.pending_orders.total }}</div>
          <div class="stat-label">Pending Orders</div>
          <div class="stat-sub">{{ kpis.pending_orders.sales }} sales · {{ kpis.pending_orders.purchases }} purchase</div>
        </div>
      </div>

      <!-- 4. Manufacturing Status (module not yet available) -->
      <div class="stat-card placeholder">
        <div class="stat-icon" style="background: linear-gradient(135deg, #8a8f98, #6b7280)"><i class="pi pi-cog" /></div>
        <div class="stat-body">
          <div class="stat-value muted">—</div>
          <div class="stat-label">Manufacturing Status</div>
          <div class="stat-sub">Coming soon</div>
        </div>
      </div>

      <!-- 5. Low Stock Alert -->
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #d66a45, #c1502e)"><i class="pi pi-exclamation-triangle" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ kpis.low_stock_count }}</div>
          <div class="stat-label">Low Stock Alert</div>
          <div class="stat-sub">at/below reorder level</div>
        </div>
      </div>

      <!-- 6. Out of Stock -->
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #b3402b, #8f2f1f)"><i class="pi pi-times-circle" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ kpis.out_of_stock_count }}</div>
          <div class="stat-label">Out of Stock</div>
          <div class="stat-sub">products with no stock</div>
        </div>
      </div>

      <!-- 7. Expenses Summary (module not yet available) -->
      <div class="stat-card placeholder">
        <div class="stat-icon" style="background: linear-gradient(135deg, #8a8f98, #6b7280)"><i class="pi pi-wallet" /></div>
        <div class="stat-body">
          <div class="stat-value muted">—</div>
          <div class="stat-label">Expenses Summary</div>
          <div class="stat-sub">Coming soon</div>
        </div>
      </div>

      <!-- 8. Profit & Loss -->
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #4c8b6c, #2c4a5e)"><i class="pi pi-chart-line" /></div>
        <div class="stat-body">
          <div class="stat-value" :class="{ negative: kpis.profit_loss.gross_profit < 0 }">{{ money(kpis.profit_loss.gross_profit) }}</div>
          <div class="stat-label">Gross Profit ({{ kpis.profit_loss.period }})</div>
          <div class="stat-sub">Rev {{ money(kpis.profit_loss.revenue) }} · COGS {{ money(kpis.profit_loss.cogs) }}</div>
        </div>
      </div>
    </div>

    <div class="panel-row">
      <!-- 9. Top Selling Products -->
      <div class="panel">
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
      </div>

      <!-- 10. Recent Activities -->
      <div class="panel">
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
      </div>
    </div>
  </div>
</template>

<style scoped>
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
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.stat-card {
  background: #fff;
  border: 1px solid #e8e4da;
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
.stat-card.placeholder {
  border-style: dashed;
  background: #fbfaf8;
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
.stat-value.muted {
  color: #b8b3a8;
}
.stat-value.negative {
  color: #c1502e;
}
.stat-label {
  color: #374151;
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
  background: #fff;
  border: 1px solid #e8e4da;
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
  border-bottom: 1px solid #e5e7eb;
}
.mini-table td {
  padding: 0.45rem 0;
  border-bottom: 1px solid #f1f5f9;
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
  border-bottom: 1px solid #f1f5f9;
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
  color: #1e2328;
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
</style>
