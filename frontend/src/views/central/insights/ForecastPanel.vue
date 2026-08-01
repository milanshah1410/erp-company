<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Chart from 'primevue/chart'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../../api/centralHttp'
import { fmtMoney } from '../billing/shared'
import { SERIES, baseOptions, fmtCompact, fmtPct } from '../analytics/shared'

const toast = useToast()
const loading = ref(false)
const data = ref<any>(null)
const months = ref(6)

const monthOptions = [3, 6, 9, 12].map((m) => ({ label: `${m} months`, value: m }))

async function load() {
  loading.value = true
  try {
    const res = await centralHttp.get('/insights/forecast', { params: { months: months.value } })
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the revenue forecast', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => data.value?.currency ?? 'INR')
const history = computed<any[]>(() => data.value?.history ?? [])
const projection = computed<any[]>(() => data.value?.projection ?? [])

// History and forecast are one continuous measure on one axis, split into two
// series so the projected half reads as projected: dashed line, hollow points,
// and a shaded band for the uncertainty range.
const chartData = computed(() => {
  const labels = [...history.value.map((h) => h.label), ...projection.value.map((p) => p.label)]
  const pad = history.value.map(() => null)
  // Repeat the last actual so the two lines meet instead of leaving a gap.
  const joinIndex = history.value.length - 1

  const forecastSeries = [...pad, ...projection.value.map((p) => p.forecast)]
  const lowSeries = [...pad, ...projection.value.map((p) => p.low)]
  const highSeries = [...pad, ...projection.value.map((p) => p.high)]
  if (joinIndex >= 0) {
    const lastActual = history.value[joinIndex].net
    forecastSeries[joinIndex] = lastActual
    lowSeries[joinIndex] = lastActual
    highSeries[joinIndex] = lastActual
  }

  return {
    labels,
    datasets: [
      {
        label: 'Forecast range',
        data: highSeries,
        borderColor: 'transparent',
        backgroundColor: 'rgba(42, 120, 214, 0.10)',
        pointRadius: 0,
        fill: '+1',
        tension: 0.25,
      },
      {
        label: 'Range floor',
        data: lowSeries,
        borderColor: 'transparent',
        backgroundColor: 'transparent',
        pointRadius: 0,
        tension: 0.25,
      },
      {
        label: 'Collected',
        data: [...history.value.map((h) => h.net), ...projection.value.map(() => null)],
        borderColor: SERIES[0],
        backgroundColor: SERIES[0],
        borderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
        tension: 0.25,
      },
      {
        label: 'Forecast',
        data: forecastSeries,
        borderColor: SERIES[1],
        backgroundColor: '#fff',
        borderWidth: 2,
        borderDash: [6, 4],
        pointRadius: 4,
        pointBorderColor: SERIES[1],
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        tension: 0.25,
      },
    ],
  }
})

const chartOptions = computed(() => {
  const base = baseOptions()
  return {
    ...base,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        filter: (ctx: any) => ctx.dataset.label !== 'Range floor' && ctx.parsed.y !== null,
        callbacks: {
          label: (ctx: any) => ` ${ctx.dataset.label}: ${fmtMoney(ctx.parsed.y, currency.value)}`,
        },
      },
    },
    scales: {
      ...base.scales,
      y: { ...base.scales.y, ticks: { color: '#6b7280', font: { size: 11 }, callback: (v: any) => fmtCompact(v, currency.value) } },
    },
  }
})

const directionSeverity = computed(() =>
  ({ growing: 'success', declining: 'danger', flat: 'secondary' })[data.value?.direction as string] ?? 'secondary',
)

const confidenceLabel = computed(() => {
  const c = data.value?.confidence ?? 0
  return c >= 0.75 ? 'High' : c >= 0.5 ? 'Moderate' : 'Low'
})

onMounted(load)
</script>

<template>
  <div class="pf-analytics">
    <div class="panel-head">
      <p class="lede">
        A least-squares trend over completed months of collected revenue, blended toward
        contracted MRR by how much the history can be trusted. Deterministic arithmetic —
        the same inputs always give the same number.
      </p>
      <div class="controls">
        <Select v-model="months" :options="monthOptions" optionLabel="label" optionValue="value"
          class="w-10" @change="load" />
        <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="loading" @click="load" />
      </div>
    </div>

    <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Fitting the trend…</div>

    <template v-else-if="data">
      <div class="tiles">
        <div class="tile">
          <span class="tile-label">Contracted MRR</span>
          <span class="tile-value">{{ fmtMoney(data.mrr, currency) }}</span>
          <span class="tile-note">{{ fmtMoney(data.arr, currency) }} annualised</span>
        </div>
        <div class="tile">
          <span class="tile-label">Next {{ months }} months</span>
          <span class="tile-value">{{ fmtMoney(data.forecast_total, currency) }}</span>
          <span class="tile-note">projected collections</span>
        </div>
        <div class="tile">
          <span class="tile-label">Trend</span>
          <span class="tile-value">{{ fmtMoney(data.trend_per_month, currency) }}</span>
          <span class="tile-note">
            <Tag :severity="directionSeverity" :value="data.direction" /> per month
          </span>
        </div>
        <div class="tile">
          <span class="tile-label">Confidence</span>
          <span class="tile-value">{{ confidenceLabel }}</span>
          <span class="tile-note">{{ fmtPct(data.confidence * 100, 0) }} · {{ data.months_of_history }} complete months</span>
        </div>
      </div>

      <figure class="figure">
        <figcaption>
          <span class="fig-title">Collected revenue and projection</span>
          <span class="fig-sub">Shaded band is the uncertainty range, widening with distance</span>
        </figcaption>
        <ul class="legend">
          <li><span class="swatch" :style="{ background: SERIES[0] }" />Collected</li>
          <li><span class="swatch dashed" :style="{ borderColor: SERIES[1] }" />Forecast</li>
          <li><span class="swatch band" />Uncertainty range</li>
        </ul>
        <div class="chart-wrap tall"><Chart type="line" :data="chartData" :options="chartOptions" /></div>
      </figure>

      <div v-if="data.notes?.length" class="notes">
        <strong>How to read this</strong>
        <ul>
          <li v-for="(n, i) in data.notes" :key="i">{{ n }}</li>
        </ul>
      </div>

      <h3 class="sub-heading">Projection</h3>
      <DataTable :value="projection" class="p-datatable-sm" dataKey="month">
        <Column field="label" header="Month" style="width: 9rem" />
        <Column header="Low">
          <template #body="{ data: row }"><span class="muted">{{ fmtMoney(row.low, currency) }}</span></template>
        </Column>
        <Column header="Forecast">
          <template #body="{ data: row }"><strong>{{ fmtMoney(row.forecast, currency) }}</strong></template>
        </Column>
        <Column header="High">
          <template #body="{ data: row }"><span class="muted">{{ fmtMoney(row.high, currency) }}</span></template>
        </Column>
        <template #empty>Nothing to project yet.</template>
      </DataTable>

      <h3 class="sub-heading">History used for the fit</h3>
      <DataTable :value="history" class="p-datatable-sm" dataKey="month">
        <Column field="label" header="Month" style="width: 9rem" />
        <Column header="Net collected">
          <template #body="{ data: row }">{{ fmtMoney(row.net, currency) }}</template>
        </Column>
        <Column header="" style="width: 12rem">
          <template #body="{ data: row }">
            <Tag v-if="row.partial" value="still accruing — excluded" severity="secondary" />
          </template>
        </Column>
      </DataTable>
    </template>
  </div>
</template>

<style scoped>
.w-10 { width: 10rem; }
.swatch.dashed { background: transparent; border: 2px dashed; height: 0; width: 14px; border-radius: 0; }
.swatch.band { background: rgba(42, 120, 214, 0.18); width: 14px; height: 10px; }
</style>
