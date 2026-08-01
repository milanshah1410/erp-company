<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Chart from 'primevue/chart'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { fmtMoney } from '../billing/shared'
import { SERIES, baseOptions, fmtCompact, fmtNumber, fmtPct } from './shared'

const toast = useToast()
const loading = ref(false)
const data = ref<any>(null)

async function load() {
  loading.value = true
  try {
    const res = await centralHttp.get('/analytics/revenue')
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load revenue analytics', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => data.value?.currency ?? 'INR')
const trend = computed<any[]>(() => data.value?.monthly_trend ?? [])

// Net collected per month. One series, so no legend box — the figure title
// names it, and the axis carries the unit.
const revenueChart = computed(() => ({
  labels: trend.value.map((m) => m.label),
  datasets: [
    {
      data: trend.value.map((m) => m.net),
      backgroundColor: SERIES[0],
      hoverBackgroundColor: '#256abf',
      borderRadius: 4,
      maxBarThickness: 26,
    },
  ],
}))

const revenueOptions = computed(() =>
  baseOptions({
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        callbacks: {
          label: (ctx: any) => ` Net collected ${fmtMoney(ctx.parsed.y, currency.value)}`,
        },
      },
    },
    scales: {
      ...baseOptions().scales,
      y: { ...baseOptions().scales.y, ticks: { color: '#6b7280', font: { size: 11 }, callback: (v: any) => fmtCompact(v, currency.value) } },
    },
  }),
)

// Signups against cancellations — the movement behind the revenue line.
const movementChart = computed(() => ({
  labels: trend.value.map((m) => m.label),
  datasets: [
    {
      label: 'New tenants',
      data: trend.value.map((m) => m.new_tenants),
      backgroundColor: SERIES[2],
      borderRadius: 4,
      maxBarThickness: 18,
    },
    {
      label: 'Cancellations',
      data: trend.value.map((m) => m.cancellations),
      backgroundColor: SERIES[1],
      borderRadius: 4,
      maxBarThickness: 18,
    },
  ],
}))

const movementOptions = computed(() =>
  baseOptions({
    scales: {
      ...baseOptions().scales,
      y: { ...baseOptions().scales.y, ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 } },
    },
  }),
)

const growthTone = computed(() => {
  const g = data.value?.growth_pct
  if (g === null || g === undefined) return ''
  return g >= 0 ? 'ok' : 'danger'
})

onMounted(load)
</script>

<template>
  <div class="pf-analytics">
    <div class="panel-head">
      <p class="lede">
        Where the money is coming from — contracted MRR, what actually landed each month,
        and the tenants carrying the book.
      </p>
      <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="loading" @click="load" />
    </div>

    <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Loading revenue analytics…</div>

    <template v-else-if="data">
      <div class="tiles">
        <div class="tile">
          <span class="tile-label">MRR</span>
          <span class="tile-value">{{ fmtMoney(data.mrr, currency) }}</span>
          <span class="tile-note">{{ fmtNumber(data.paying_tenants) }} paying tenants</span>
        </div>
        <div class="tile">
          <span class="tile-label">ARR</span>
          <span class="tile-value">{{ fmtMoney(data.arr, currency) }}</span>
          <span class="tile-note">MRR × 12</span>
        </div>
        <div class="tile">
          <span class="tile-label">ARPU</span>
          <span class="tile-value">{{ fmtMoney(data.arpu, currency) }}</span>
          <span class="tile-note">per paying tenant / month</span>
        </div>
        <div class="tile">
          <span class="tile-label">This month</span>
          <span class="tile-value">{{ fmtMoney(data.this_month?.net, currency) }}</span>
          <span class="tile-note" :class="growthTone">
            <template v-if="data.growth_pct !== null">
              {{ data.growth_pct >= 0 ? '▲' : '▼' }} {{ fmtPct(Math.abs(data.growth_pct)) }} vs last month
            </template>
            <template v-else>no prior month to compare</template>
          </span>
        </div>
        <div class="tile">
          <span class="tile-label">Collected to date</span>
          <span class="tile-value">{{ fmtMoney(data.lifetime_net, currency) }}</span>
          <span class="tile-note">net of refunds</span>
        </div>
      </div>

      <div class="figures">
        <figure class="figure">
          <figcaption>
            <span class="fig-title">Net collected per month</span>
            <span class="fig-sub">Last 12 months, refunds deducted</span>
          </figcaption>
          <div class="chart-wrap"><Chart type="bar" :data="revenueChart" :options="revenueOptions" /></div>
        </figure>

        <figure class="figure">
          <figcaption>
            <span class="fig-title">Customer movement</span>
            <span class="fig-sub">New workspaces against cancellations</span>
          </figcaption>
          <ul class="legend">
            <li><span class="swatch" :style="{ background: SERIES[2] }" />New tenants</li>
            <li><span class="swatch" :style="{ background: SERIES[1] }" />Cancellations</li>
          </ul>
          <div class="chart-wrap"><Chart type="bar" :data="movementChart" :options="movementOptions" /></div>
        </figure>
      </div>

      <h3 class="sub-heading">Top tenants by net revenue</h3>
      <DataTable :value="data.top_tenants" class="p-datatable-sm" dataKey="tenant_id">
        <Column field="tenant_name" header="Tenant" />
        <Column header="Net revenue" style="width: 12rem">
          <template #body="{ data: row }">{{ fmtMoney(row.net_revenue, currency) }}</template>
        </Column>
        <template #empty>No collected revenue yet.</template>
      </DataTable>

      <h3 class="sub-heading">Monthly detail</h3>
      <DataTable :value="trend" class="p-datatable-sm" dataKey="month">
        <Column field="label" header="Month" style="width: 8rem" />
        <Column header="Gross">
          <template #body="{ data: row }">{{ fmtMoney(row.gross, currency) }}</template>
        </Column>
        <Column header="Refunds">
          <template #body="{ data: row }">{{ fmtMoney(row.refunds, currency) }}</template>
        </Column>
        <Column header="Net">
          <template #body="{ data: row }"><strong>{{ fmtMoney(row.net, currency) }}</strong></template>
        </Column>
        <Column field="invoices" header="Invoices" style="width: 7rem" />
        <Column field="new_tenants" header="New" style="width: 6rem" />
        <Column field="cancellations" header="Cancelled" style="width: 7rem" />
      </DataTable>
    </template>
  </div>
</template>

<!-- Layout/typography for every Analytics & Insights panel lives in
     ./panel.css, imported once by the parent view. Every rule there is nested
     under `.pf-analytics`, so the shared sheet cannot leak into the rest of the
     platform panel. -->
