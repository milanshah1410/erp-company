<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import Chart from 'primevue/chart'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import GettingStarted from '../components/GettingStarted.vue'

interface Stat {
  label: string
  value: number | string
  icon: string
  color: string
}

const stats = ref<Stat[]>([
  { label: 'Products', value: '—', icon: 'pi pi-box', color: 'linear-gradient(135deg, #3d637c, #2c4a5e)' },
  { label: 'Categories', value: '—', icon: 'pi pi-sitemap', color: 'linear-gradient(135deg, #eaad48, #c98730)' },
  { label: 'Warehouses', value: '—', icon: 'pi pi-building', color: 'linear-gradient(135deg, #5fa483, #4c8b6c)' },
  { label: 'Suppliers', value: '—', icon: 'pi pi-truck', color: 'linear-gradient(135deg, #2c4a5e, #1e2328)' },
])

const kpis = ref<{
  total_stock_value: number
  low_stock_count: number
  pending_po_count: number
  top_skus: { sku: string; name: string; qty_sold: number }[]
}>({ total_stock_value: 0, low_stock_count: 0, pending_po_count: 0, top_skus: [] })

async function loadCount(endpoint: string, index: number) {
  try {
    const { data } = await http.get(endpoint, { params: { per_page: 1 } })
    stats.value[index].value = data.meta?.total ?? (data.data?.length ?? 0)
  } catch {
    stats.value[index].value = 0
  }
}

async function loadKpis() {
  try {
    const { data } = await http.get('/dashboard/kpis')
    kpis.value = data
  } catch {
    // Dashboard KPIs are best-effort; leave the zeroed defaults on failure.
  }
}

function money(v: number) {
  return formatCurrency(v, { maximumFractionDigits: 0 })
}

const chartData = computed(() => ({
  labels: kpis.value.top_skus.map((s) => s.sku),
  datasets: [{ label: 'Qty Sold (30d)', backgroundColor: '#e8a33d', data: kpis.value.top_skus.map((s) => s.qty_sold) }],
}))

const chartOptions = {
  plugins: { legend: { display: false } },
  scales: { y: { beginAtZero: true } },
}

onMounted(() => {
  loadCount('/products', 0)
  loadCount('/categories', 1)
  loadCount('/warehouses', 2)
  loadCount('/suppliers', 3)
  loadKpis()
})
</script>

<template>
  <div class="dashboard">
    <h1>Dashboard</h1>
    <p class="subtitle">Overview of your inventory system</p>

    <GettingStarted />

    <div class="stat-grid">
      <div v-for="stat in stats" :key="stat.label" class="stat-card">
        <div class="stat-icon" :style="{ background: stat.color }">
          <i :class="stat.icon" />
        </div>
        <div class="stat-body">
          <div class="stat-value">{{ stat.value }}</div>
          <div class="stat-label">{{ stat.label }}</div>
        </div>
      </div>
    </div>

    <div class="barcode-divider section-divider" />

    <h2>Key Metrics</h2>
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #5fa483, #4c8b6c)"><i class="pi pi-wallet" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ money(kpis.total_stock_value) }}</div>
          <div class="stat-label">Total Stock Value</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #d66a45, #c1502e)"><i class="pi pi-exclamation-triangle" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ kpis.low_stock_count }}</div>
          <div class="stat-label">Low-Stock Alerts</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #eaad48, #c98730)"><i class="pi pi-clock" /></div>
        <div class="stat-body">
          <div class="stat-value">{{ kpis.pending_po_count }}</div>
          <div class="stat-label">Pending POs</div>
        </div>
      </div>
    </div>

    <div class="panel-row">
      <div class="panel">
        <h2>Top SKUs (last 30 days)</h2>
        <table class="top-skus" v-if="kpis.top_skus.length">
          <thead><tr><th>SKU</th><th>Name</th><th>Qty Sold</th></tr></thead>
          <tbody>
            <tr v-for="s in kpis.top_skus" :key="s.sku">
              <td>{{ s.sku }}</td>
              <td>{{ s.name }}</td>
              <td>{{ s.qty_sold }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="empty">No sales activity in the last 30 days.</p>
      </div>
      <div class="panel">
        <h2>Top SKUs Chart</h2>
        <Chart v-if="kpis.top_skus.length" type="bar" :data="chartData" :options="chartOptions" class="chart" />
        <p v-else class="empty">No data to chart yet.</p>
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
  margin: 1.75rem 0 0.75rem;
}
.subtitle {
  color: #6b7280;
  margin-bottom: 1.5rem;
}
.section-divider {
  margin: 1.5rem 0;
}
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
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
}
.stat-value {
  font-family: 'Space Grotesk', 'Inter', sans-serif;
  font-size: 1.6rem;
  font-weight: 700;
  color: var(--graphite, #1e2328);
}
.stat-label {
  color: #6b7280;
  font-size: 0.85rem;
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
.panel h2 {
  margin-top: 0;
}
.top-skus {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.top-skus th {
  text-align: left;
  color: #6b7280;
  font-weight: 500;
  padding: 0.4rem 0;
  border-bottom: 1px solid #e5e7eb;
}
.top-skus td {
  padding: 0.45rem 0;
  border-bottom: 1px solid #f1f5f9;
}
.chart {
  max-height: 260px;
}
.empty {
  color: #6b7280;
  font-size: 0.9rem;
}
</style>
