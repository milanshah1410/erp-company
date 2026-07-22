<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Chart from 'primevue/chart'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { fmtMoney, subscriptionSeverity } from './shared'

const toast = useToast()
const loading = ref(false)
const summary = ref<any>(null)

async function load() {
  loading.value = true
  try {
    const { data } = await centralHttp.get('/billing/revenue')
    summary.value = data.data ?? data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load revenue summary', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => summary.value?.currency ?? 'INR')

// Month-over-month delta for the "this month" card.
const momDelta = computed(() => {
  const cur = Number(summary.value?.revenue_this_month ?? 0)
  const prev = Number(summary.value?.revenue_last_month ?? 0)
  if (!prev) return null
  return Math.round(((cur - prev) / prev) * 100)
})

const statusRow = computed(() => Object.entries(summary.value?.subscriptions_by_status ?? {}) as [string, number][])

const chartData = computed(() => ({
  labels: (summary.value?.monthly_trend ?? []).map((m: any) => m.label),
  datasets: [
    {
      label: `Revenue (${currency.value})`,
      data: (summary.value?.monthly_trend ?? []).map((m: any) => m.revenue),
      // Validated for ≥3:1 contrast on the light surface (the brand amber
      // #e8a33d only reaches 2.1:1 and washes out as a data fill).
      backgroundColor: '#2a78d6',
      hoverBackgroundColor: '#256abf',
      borderRadius: 4,
      maxBarThickness: 28,
    },
  ],
}))

const chartOptions = {
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    y: { beginAtZero: true, ticks: { precision: 0 } },
    x: { grid: { display: false } },
  },
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading && !summary" class="loading"><i class="pi pi-spin pi-spinner" /> Loading revenue…</div>

    <template v-if="summary">
      <div class="stat-grid">
        <div class="stat-card">
          <span class="stat-label">MRR</span>
          <span class="stat-value">{{ fmtMoney(summary.mrr, currency) }}</span>
          <span class="stat-sub">ARR {{ fmtMoney(Number(summary.mrr) * 12, currency) }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Revenue this month</span>
          <span class="stat-value">{{ fmtMoney(summary.revenue_this_month, currency) }}</span>
          <span class="stat-sub">
            <template v-if="momDelta !== null">
              <span :class="momDelta >= 0 ? 'up' : 'down'">
                <i :class="momDelta >= 0 ? 'pi pi-arrow-up' : 'pi pi-arrow-down'" /> {{ Math.abs(momDelta) }}%
              </span>
              vs last month ({{ fmtMoney(summary.revenue_last_month, currency) }})
            </template>
            <template v-else>last month {{ fmtMoney(summary.revenue_last_month, currency) }}</template>
          </span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Net revenue (lifetime)</span>
          <span class="stat-value">{{ fmtMoney(summary.net_revenue, currency) }}</span>
          <span class="stat-sub">gross {{ fmtMoney(summary.gross_revenue, currency) }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Refunds (lifetime)</span>
          <span class="stat-value">{{ fmtMoney(summary.refunds_total, currency) }}</span>
          <span class="stat-sub">already deducted from net</span>
        </div>
      </div>

      <div class="status-row">
        <span class="status-title">Subscriptions:</span>
        <Tag v-for="[status, count] in statusRow" :key="status"
          :value="`${status.replace('_', ' ')}: ${count}`"
          :severity="subscriptionSeverity[status] ?? 'secondary'" class="status-tag" />
      </div>

      <div class="panel-grid">
        <div class="panel">
          <h3>Revenue — last 12 months</h3>
          <div class="chart-wrap">
            <Chart type="bar" :data="chartData" :options="chartOptions" style="height: 100%" />
          </div>
        </div>

        <div class="panel">
          <h3>Plan breakdown</h3>
          <DataTable :value="summary.plan_breakdown" class="p-datatable-sm" dataKey="plan">
            <Column field="plan" header="Plan" />
            <Column header="Price">
              <template #body="{ data }">
                {{ fmtMoney(data.price, currency) }} <span class="muted">/ {{ data.billing_cycle === 'yearly' ? 'yr' : 'mo' }}</span>
              </template>
            </Column>
            <Column field="live_subscriptions" header="Live subs" style="width: 7rem" />
            <template #empty>No plans yet.</template>
          </DataTable>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.loading { color: #6b7280; padding: 2rem 0; }
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
.stat-card {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem 1.15rem;
  display: flex; flex-direction: column; gap: 0.3rem;
}
.stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
.stat-value { font-size: 1.45rem; font-weight: 700; color: #1f2937; }
.stat-sub { font-size: 0.78rem; color: #6b7280; }
.up { color: #16a34a; font-weight: 600; }
.down { color: #dc2626; font-weight: 600; }
.status-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1rem; }
.status-title { font-size: 0.85rem; font-weight: 600; color: #374151; }
.status-tag { text-transform: capitalize; }
.panel-grid { display: grid; grid-template-columns: 3fr 2fr; gap: 1rem; }
@media (max-width: 900px) { .panel-grid { grid-template-columns: 1fr; } }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem 1.15rem; }
.panel h3 { font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; color: #374151; }
.chart-wrap { height: 280px; }
.muted { color: #9ca3af; font-size: 0.8rem; }
</style>
