<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Chart from 'primevue/chart'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
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
    const res = await centralHttp.get('/analytics/plans')
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load plan analytics', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => data.value?.currency ?? 'INR')
const rows = computed<any[]>(() => data.value?.rows ?? [])

// Subscription state per tier. Stacked, because the segments are parts of one
// whole (every subscription on a plan is in exactly one state); a 2px surface
// gap keeps the segments from reading as a single block.
const mixChart = computed(() => ({
  labels: rows.value.map((r) => r.name),
  datasets: [
    { label: 'Live', data: rows.value.map((r) => r.live), backgroundColor: SERIES[0], borderColor: '#fff', borderWidth: 2, borderRadius: 4, maxBarThickness: 34 },
    { label: 'Trialing', data: rows.value.map((r) => r.trialing), backgroundColor: SERIES[2], borderColor: '#fff', borderWidth: 2, borderRadius: 4, maxBarThickness: 34 },
    { label: 'Cancelled', data: rows.value.map((r) => r.cancelled), backgroundColor: SERIES[1], borderColor: '#fff', borderWidth: 2, borderRadius: 4, maxBarThickness: 34 },
  ],
}))

const mixOptions = computed(() => {
  const base = baseOptions()
  return {
    ...base,
    scales: {
      x: { ...base.scales.x, stacked: true },
      y: { ...base.scales.y, stacked: true, ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 } },
    },
  }
})

const mrrChart = computed(() => ({
  labels: rows.value.map((r) => r.name),
  datasets: [
    {
      data: rows.value.map((r) => r.mrr),
      backgroundColor: SERIES[0],
      hoverBackgroundColor: '#256abf',
      borderRadius: 4,
      maxBarThickness: 34,
    },
  ],
}))

const mrrOptions = computed(() => {
  const base = baseOptions()
  return {
    ...base,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        callbacks: { label: (ctx: any) => ` ${fmtMoney(ctx.parsed.y, currency.value)} MRR` },
      },
    },
    scales: {
      ...base.scales,
      y: { ...base.scales.y, ticks: { color: '#6b7280', font: { size: 11 }, callback: (v: any) => fmtCompact(v, currency.value) } },
    },
  }
})

const bestConverting = computed(() => {
  const scored = rows.value.filter((r) => r.conversion_pct !== null)
  return scored.length ? scored.reduce((a, b) => (b.conversion_pct > a.conversion_pct ? b : a)) : null
})

onMounted(load)
</script>

<template>
  <div class="pf-analytics">
    <div class="panel-head">
      <p class="lede">
        The economics of the catalogue — who sits on which tier, what each contributes,
        how well its trials convert and how long its subscribers last.
      </p>
      <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="loading" @click="load" />
    </div>

    <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Loading plan analytics…</div>

    <template v-else-if="data">
      <Message v-if="data.orphaned" severity="warn" :closable="false" class="orphan-note">
        {{ data.orphaned }} subscription(s) point at a plan that is no longer in the catalogue.
        They contribute nothing to the figures below — repoint them from Billing → Subscriptions.
      </Message>

      <div class="tiles">
        <div class="tile">
          <span class="tile-label">Live subscriptions</span>
          <span class="tile-value">{{ fmtNumber(data.total_live) }}</span>
          <span class="tile-note">active or past due</span>
        </div>
        <div class="tile">
          <span class="tile-label">Total MRR</span>
          <span class="tile-value">{{ fmtMoney(data.total_mrr, currency) }}</span>
          <span class="tile-note">across {{ rows.length }} tier(s)</span>
        </div>
        <div class="tile">
          <span class="tile-label">Largest tier by MRR</span>
          <span class="tile-value">{{ rows.slice().sort((a, b) => b.mrr - a.mrr)[0]?.name ?? '—' }}</span>
          <span class="tile-note">{{ fmtPct(rows.slice().sort((a, b) => b.mrr - a.mrr)[0]?.mrr_share_pct) }} of MRR</span>
        </div>
        <div class="tile">
          <span class="tile-label">Best trial conversion</span>
          <span class="tile-value">{{ bestConverting?.name ?? '—' }}</span>
          <span class="tile-note ok">{{ fmtPct(bestConverting?.conversion_pct) }} converted</span>
        </div>
      </div>

      <div class="figures">
        <figure class="figure">
          <figcaption>
            <span class="fig-title">Subscription state by tier</span>
            <span class="fig-sub">Every subscription ever opened on each plan</span>
          </figcaption>
          <ul class="legend">
            <li><span class="swatch" :style="{ background: SERIES[0] }" />Live</li>
            <li><span class="swatch" :style="{ background: SERIES[2] }" />Trialing</li>
            <li><span class="swatch" :style="{ background: SERIES[1] }" />Cancelled</li>
          </ul>
          <div class="chart-wrap"><Chart type="bar" :data="mixChart" :options="mixOptions" /></div>
        </figure>

        <figure class="figure">
          <figcaption>
            <span class="fig-title">MRR contribution by tier</span>
            <span class="fig-sub">Live subscriptions × monthly-equivalent price</span>
          </figcaption>
          <div class="chart-wrap"><Chart type="bar" :data="mrrChart" :options="mrrOptions" /></div>
        </figure>
      </div>

      <h3 class="sub-heading">Plan detail</h3>
      <DataTable :value="rows" class="p-datatable-sm" dataKey="plan_id">
        <Column field="name" header="Plan" sortable>
          <template #body="{ data: row }">
            <div class="stack">
              <span class="strong">{{ row.name }}<Tag v-if="row.is_custom" value="custom" severity="secondary" class="ml" /></span>
              <span class="muted">{{ fmtMoney(row.price, currency) }} / {{ row.billing_cycle }}</span>
            </div>
          </template>
        </Column>
        <Column field="live" header="Live" sortable style="width: 6.5rem" />
        <Column field="trialing" header="Trialing" sortable style="width: 7rem" />
        <Column field="cancelled" header="Cancelled" sortable style="width: 8rem" />
        <Column field="mrr" header="MRR" sortable style="width: 10rem">
          <template #body="{ data: row }">{{ fmtMoney(row.mrr, currency) }}</template>
        </Column>
        <Column field="mrr_share_pct" header="Share" sortable style="width: 10rem">
          <template #body="{ data: row }">
            <div class="stack">
              <span class="num">{{ fmtPct(row.mrr_share_pct) }}</span>
              <span class="meter"><span :style="{ width: `${row.mrr_share_pct}%`, background: SERIES[0] }" /></span>
            </div>
          </template>
        </Column>
        <Column field="conversion_pct" header="Trial → paid" sortable style="width: 9.5rem">
          <template #body="{ data: row }">
            <span v-if="row.conversion_pct === null" class="muted" title="No trial has finished on this plan yet">—</span>
            <span v-else class="num">{{ fmtPct(row.conversion_pct) }}</span>
          </template>
        </Column>
        <Column field="churn_pct" header="Churned" sortable style="width: 8rem">
          <template #body="{ data: row }">
            <span class="num" :class="{ reasons: row.churn_pct > 0 }">{{ fmtPct(row.churn_pct) }}</span>
          </template>
        </Column>
        <Column field="avg_tenure_days" header="Avg tenure" sortable style="width: 9rem">
          <template #body="{ data: row }"><span class="num">{{ fmtNumber(row.avg_tenure_days) }} days</span></template>
        </Column>
        <Column field="lifetime_net" header="Lifetime net" sortable style="width: 11rem">
          <template #body="{ data: row }">{{ fmtMoney(row.lifetime_net, currency) }}</template>
        </Column>
        <template #empty>No plans in the catalogue.</template>
      </DataTable>
    </template>
  </div>
</template>

<style scoped>
.orphan-note { margin-bottom: 1rem; }
.ml { margin-left: 0.4rem; }
</style>
