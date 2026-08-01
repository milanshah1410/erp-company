<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Chart from 'primevue/chart'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
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
    const res = await centralHttp.get('/analytics/industry')
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load industry analytics', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => data.value?.currency ?? 'INR')
const rows = computed<any[]>(() => data.value?.rows ?? [])

// Ranked magnitude across many named categories → horizontal bars, not a pie.
// One measure per figure, so a single hue and no legend.
const mrrChart = computed(() => ({
  labels: rows.value.map((r) => r.label),
  datasets: [
    {
      data: rows.value.map((r) => r.mrr),
      backgroundColor: SERIES[0],
      hoverBackgroundColor: '#256abf',
      borderRadius: 4,
      maxBarThickness: 20,
    },
  ],
}))

const tenantChart = computed(() => ({
  labels: rows.value.map((r) => r.label),
  datasets: [
    {
      data: rows.value.map((r) => r.tenants),
      backgroundColor: SERIES[2],
      hoverBackgroundColor: '#35855b',
      borderRadius: 4,
      maxBarThickness: 20,
    },
  ],
}))

function horizontalOptions(money: boolean) {
  const base = baseOptions()
  return {
    ...base,
    indexAxis: 'y',
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        callbacks: {
          label: (ctx: any) => (money ? ` ${fmtMoney(ctx.parsed.x, currency.value)}` : ` ${fmtNumber(ctx.parsed.x)} tenants`),
        },
      },
    },
    scales: {
      x: {
        beginAtZero: true,
        border: { display: false },
        grid: { color: '#eceef1' },
        ticks: {
          color: '#6b7280',
          font: { size: 11 },
          precision: money ? undefined : 0,
          callback: (v: any) => (money ? fmtCompact(v, currency.value) : v),
        },
      },
      y: { border: { display: false }, grid: { display: false }, ticks: { color: '#6b7280', font: { size: 11 } } },
    },
  }
}

const chartHeight = computed(() => ({ height: `${Math.max(200, rows.value.length * 30 + 40)}px` }))

onMounted(load)
</script>

<template>
  <div class="pf-analytics">
    <div class="panel-head">
      <p class="lede">
        Which verticals the customer base actually sits in, what each is worth, and which
        templates we sell but nobody has bought.
      </p>
      <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="loading" @click="load" />
    </div>

    <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Loading industry analytics…</div>

    <template v-else-if="data">
      <div class="tiles">
        <div class="tile">
          <span class="tile-label">Tenants</span>
          <span class="tile-value">{{ fmtNumber(data.total_tenants) }}</span>
          <span class="tile-note">across {{ rows.length }} vertical(s)</span>
        </div>
        <div class="tile">
          <span class="tile-label">Total MRR</span>
          <span class="tile-value">{{ fmtMoney(data.total_mrr, currency) }}</span>
          <span class="tile-note">live paid subscriptions</span>
        </div>
        <div class="tile">
          <span class="tile-label">Leading vertical</span>
          <span class="tile-value">{{ rows[0]?.label ?? '—' }}</span>
          <span class="tile-note">{{ fmtPct(rows[0]?.mrr_share_pct) }} of MRR</span>
        </div>
        <div class="tile">
          <span class="tile-label">Unsold templates</span>
          <span class="tile-value">{{ data.unsold.length }}</span>
          <span class="tile-note">active in the catalogue, zero tenants</span>
        </div>
      </div>

      <div class="figures">
        <figure class="figure">
          <figcaption>
            <span class="fig-title">MRR by vertical</span>
            <span class="fig-sub">Monthly-equivalent value of live subscriptions</span>
          </figcaption>
          <div class="chart-wrap" :style="chartHeight">
            <Chart type="bar" :data="mrrChart" :options="horizontalOptions(true)" />
          </div>
        </figure>

        <figure class="figure">
          <figcaption>
            <span class="fig-title">Tenants by vertical</span>
            <span class="fig-sub">Every workspace, paying or not</span>
          </figcaption>
          <div class="chart-wrap" :style="chartHeight">
            <Chart type="bar" :data="tenantChart" :options="horizontalOptions(false)" />
          </div>
        </figure>
      </div>

      <h3 class="sub-heading">Vertical detail</h3>
      <DataTable :value="rows" class="p-datatable-sm" dataKey="label" sortField="mrr" :sortOrder="-1">
        <Column field="label" header="Industry" sortable>
          <template #body="{ data: row }">
            <span :class="{ muted: !row.key }">{{ row.label }}</span>
          </template>
        </Column>
        <Column field="tenants" header="Tenants" sortable style="width: 7rem" />
        <Column field="paying" header="Paying" sortable style="width: 7rem" />
        <Column field="trialing" header="Trialing" sortable style="width: 7rem" />
        <Column field="mrr" header="MRR" sortable style="width: 10rem">
          <template #body="{ data: row }">{{ fmtMoney(row.mrr, currency) }}</template>
        </Column>
        <Column field="mrr_share_pct" header="Share of MRR" sortable style="width: 11rem">
          <template #body="{ data: row }">
            <div class="stack">
              <span class="num">{{ fmtPct(row.mrr_share_pct) }}</span>
              <span class="meter"><span :style="{ width: `${row.mrr_share_pct}%`, background: SERIES[0] }" /></span>
            </div>
          </template>
        </Column>
        <Column field="arpu" header="ARPU" sortable style="width: 9rem">
          <template #body="{ data: row }">{{ fmtMoney(row.arpu, currency) }}</template>
        </Column>
        <Column field="churn_pct" header="Churned" sortable style="width: 8rem">
          <template #body="{ data: row }">
            <span :class="{ reasons: row.churn_pct > 0 }">{{ fmtPct(row.churn_pct) }}</span>
          </template>
        </Column>
        <template #empty>No tenants recorded yet.</template>
      </DataTable>

      <template v-if="data.unsold.length">
        <h3 class="sub-heading">Templates with no customers</h3>
        <p class="muted">
          These verticals are live in the signup catalogue but nobody has picked them — either
          the demand is not there or the funnel is not reaching it.
        </p>
        <div class="controls" style="margin-top: 0.5rem">
          <Tag v-for="t in data.unsold" :key="t.key" :value="t.label" severity="secondary" />
        </div>
      </template>
    </template>
  </div>
</template>
