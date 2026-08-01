<script setup lang="ts">
/**
 * Platform dashboard (/platform) — Volume 11 §"Dashboard".
 *
 * The business at a glance, in three layers: headline figures, then the charts
 * behind them, then the payments ledger. Navigation lives in the sidebar, so
 * this screen is figures only — each headline card doubles as a link into the
 * panel that owns its number, which is the one thing the old launcher grid was
 * good for.
 *
 * Nothing here is a second opinion on a number: every figure comes from
 * /central/dashboard, which composes the same reads the deep panels use. Blocks
 * this operator has no permission for come back null and are replaced by a
 * "no access" card — never by a zero, which would read as "we earned nothing".
 *
 * Charting rules held to deliberately:
 *  - API calls and AI calls are two counters three orders of magnitude apart, so
 *    they get one small chart each. Never two y-scales on one plot.
 *  - Counters follow the window control; storage is a snapshot of now, so it
 *    does not move when the window changes.
 *  - Every chart has a table twin (the ▸ Figures disclosure under it), so no
 *    value is reachable only by hovering.
 *  - Series colours come from the validated platform palette in
 *    ./analytics/shared.ts and are assigned per entity, never per rank.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Chart from 'primevue/chart'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import centralHttp from '../../api/centralHttp'
import { useCentralAuthStore } from '../../stores/centralAuth'
import { fmtMoney, fmtDate, invoiceSeverity } from './billing/shared'
import { SERIES, baseOptions, fmtBytes, fmtCompact, fmtNumber, fmtPct } from './analytics/shared'

const router = useRouter()
const auth = useCentralAuthStore()
const toast = useToast()

const operator = computed(() => auth.operator)

const data = ref<any>(null)
const loading = ref(false)
const days = ref(30)
const windowOptions = [
  { label: '7d', value: 7 },
  { label: '30d', value: 30 },
  { label: '90d', value: 90 },
]

// The figures band is permissioned, even though all 11 stock roles hold it — a
// hand-tuned role that does not gets a plain welcome and no failed request.
const canSeeFigures = computed(() => auth.can('platform.dashboard.view'))

async function load(fresh = false) {
  loading.value = true
  try {
    const res = await centralHttp.get('/dashboard', { params: { days: days.value, fresh: fresh ? 1 : undefined } })
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load the dashboard', life: 4000 })
  } finally {
    loading.value = false
  }
}

const currency = computed(() => data.value?.currency ?? 'INR')
const tenants = computed(() => data.value?.tenants ?? null)
const revenue = computed(() => data.value?.revenue ?? null)
const payments = computed(() => data.value?.payments ?? null)
const usage = computed(() => data.value?.usage ?? null)
const sections = computed(() => data.value?.sections ?? { revenue: false, payments: false, usage: false })

// -- Headline cards ---------------------------------------------------------
// Each links to the panel that owns the number, and only when this operator can
// open it — the card is the navigation the launcher grid used to be.
type Delta = { text: string; tone: 'up' | 'down' | 'flat' } | null

function delta(pct: number | null | undefined, suffix = 'vs last month'): Delta {
  if (pct === null || pct === undefined) return null
  if (pct === 0) return { text: `level ${suffix}`, tone: 'flat' }
  return { text: `${pct >= 0 ? '▲' : '▼'} ${fmtPct(Math.abs(pct), 0)} ${suffix}`, tone: pct >= 0 ? 'up' : 'down' }
}

const cards = computed(() => {
  if (!data.value) return []

  const out: any[] = [
    {
      key: 'tenants',
      label: 'Total tenants',
      value: fmtNumber(tenants.value.total),
      icon: 'pi pi-building',
      tint: 'blue',
      note: `${fmtNumber(tenants.value.new_this_month)} new this month`,
      delta: delta(tenants.value.signup_growth_pct, 'vs last month'),
      to: '/platform/tenants',
      permission: 'tenants.view',
    },
    {
      key: 'active',
      label: 'Active workspaces',
      value: fmtNumber(tenants.value.active),
      icon: 'pi pi-check-circle',
      tint: 'green',
      note: tenants.value.past_due
        ? `${fmtNumber(tenants.value.past_due)} past due · ${fmtNumber(tenants.value.trial)} on trial`
        : `${fmtNumber(tenants.value.trial)} on trial · all paid up`,
      to: '/platform/tenants',
      permission: 'tenants.view',
    },
  ]

  if (sections.value.revenue && revenue.value) {
    out.push(
      {
        key: 'mrr',
        label: 'MRR',
        value: fmtMoney(revenue.value.mrr, currency.value),
        icon: 'pi pi-chart-line',
        tint: 'amber',
        note: `${fmtMoney(revenue.value.arr, currency.value)} ARR · ${fmtNumber(revenue.value.paying_tenants)} paying`,
        to: '/platform/billing',
        permission: 'subscriptions.view',
      },
      {
        key: 'revenue',
        label: 'Revenue this month',
        value: fmtMoney(revenue.value.this_month_net, currency.value),
        icon: 'pi pi-indian-rupee',
        tint: 'purple',
        note: `${fmtMoney(revenue.value.lifetime_net, currency.value)} collected to date`,
        delta: delta(revenue.value.growth_pct),
        to: '/platform/billing',
        permission: 'revenue.view',
      },
    )
  }

  if (sections.value.payments && payments.value) {
    out.push({
      key: 'outstanding',
      label: 'Outstanding',
      value: fmtMoney(payments.value.outstanding_amount, currency.value),
      icon: 'pi pi-inbox',
      tint: payments.value.overdue_count ? 'red' : 'blue',
      note: `${fmtNumber(payments.value.outstanding_count)} raised · ${fmtNumber(payments.value.overdue_count)} overdue`,
      to: '/platform/billing',
      permission: 'renewals.view',
    })
  }

  if (sections.value.usage && usage.value) {
    out.push({
      key: 'usage',
      label: 'API usage',
      value: fmtNumber(usage.value.api_calls),
      icon: 'pi pi-bolt',
      tint: 'blue',
      note: `calls in ${days.value} days · ${fmtBytes(usage.value.storage_bytes)} stored`,
      to: '/platform/usage',
      permission: 'usage.view',
    })
  }

  return out
})

function openCard(card: any) {
  if (card.to && auth.can(card.permission)) router.push(card.to)
}

// -- Charts -----------------------------------------------------------------
const trend = computed<any[]>(() => revenue.value?.monthly_trend ?? [])
const movement = computed<any[]>(() => tenants.value?.monthly_movement ?? [])
const usageSeries = computed<any[]>(() => usage.value?.series ?? [])

/** Rounded bar tops, thin marks, 2px surface gap between adjacent bars. */
function bar(values: number[], color: string, label: string) {
  return {
    label,
    data: values,
    backgroundColor: color,
    borderRadius: 4,
    maxBarThickness: 22,
    borderSkipped: false,
    borderColor: '#fff',
    borderWidth: { top: 0, right: 1, bottom: 0, left: 1 },
  }
}

/** 2px line, filled to a faint wash, markers only on hover. */
function area(values: number[], color: string, label: string) {
  return {
    label,
    data: values,
    borderColor: color,
    backgroundColor: `${color}1f`,
    borderWidth: 2,
    fill: true,
    tension: 0.32,
    pointRadius: 0,
    pointHoverRadius: 4,
    pointHoverBackgroundColor: color,
    pointHoverBorderColor: '#fff',
    pointHoverBorderWidth: 2,
  }
}

const revenueChart = computed(() => ({
  labels: trend.value.map((m) => m.label),
  datasets: [bar(trend.value.map((m) => m.net), SERIES[0], 'Net collected')],
}))

const revenueOptions = computed(() =>
  baseOptions({
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        callbacks: { label: (ctx: any) => ` Net collected ${fmtMoney(ctx.parsed.y, currency.value)}` },
      },
    },
    scales: {
      ...baseOptions().scales,
      y: {
        ...baseOptions().scales.y,
        ticks: { color: '#6b7280', font: { size: 11 }, callback: (v: any) => fmtCompact(v, currency.value) },
      },
    },
  }),
)

// New workspaces against cancellations — the movement behind every other figure.
const movementChart = computed(() => ({
  labels: movement.value.map((m) => m.label),
  datasets: [
    bar(movement.value.map((m) => m.new_tenants), SERIES[2], 'New tenants'),
    bar(movement.value.map((m) => m.cancellations), SERIES[1], 'Cancellations'),
  ],
}))

const countOptions = computed(() =>
  baseOptions({
    scales: {
      ...baseOptions().scales,
      y: { ...baseOptions().scales.y, ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 } },
    },
  }),
)

// Lifecycle mix. Colour is pinned per bucket (never per size), the legend
// carries the label and the count, and the slices are separated by a surface
// gap — so identity survives both a recount and colour-blindness.
const MIX = [
  { key: 'active', label: 'Active', color: SERIES[2] },
  { key: 'trial', label: 'On trial', color: SERIES[0] },
  { key: 'expired', label: 'Expired', color: SERIES[1] },
  { key: 'no_subscription', label: 'Never subscribed', color: SERIES[3] },
] as const

const mixRows = computed(() =>
  MIX.map((m) => ({
    ...m,
    value: tenants.value?.[m.key] ?? 0,
    share: tenants.value?.total ? ((tenants.value[m.key] ?? 0) / tenants.value.total) * 100 : 0,
  })),
)

const mixChart = computed(() => ({
  labels: mixRows.value.map((r) => r.label),
  datasets: [
    {
      data: mixRows.value.map((r) => r.value),
      backgroundColor: mixRows.value.map((r) => r.color),
      borderColor: '#fff',
      borderWidth: 2,
      hoverOffset: 6,
    },
  ],
}))

const mixOptions = {
  maintainAspectRatio: false,
  cutout: '70%',
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: '#1f2937',
      padding: 10,
      callbacks: { label: (ctx: any) => ` ${ctx.label}: ${ctx.parsed} tenants` },
    },
  },
}

const apiChart = computed(() => ({
  labels: usageSeries.value.map((p) => p.label),
  datasets: [area(usageSeries.value.map((p) => p.api_calls), SERIES[0], 'API calls')],
}))

const aiChart = computed(() => ({
  labels: usageSeries.value.map((p) => p.label),
  datasets: [area(usageSeries.value.map((p) => p.ai_calls), SERIES[3], 'AI calls')],
}))

// One shared spec for the two usage sparklines: a bare x-axis (the window is
// named in the card) and few ticks, because they are read for shape.
const sparkOptions = computed(() =>
  baseOptions({
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        callbacks: { label: (ctx: any) => ` ${fmtNumber(ctx.parsed.y)} calls` },
      },
    },
    scales: {
      y: { ...baseOptions().scales.y, ticks: { color: '#6b7280', font: { size: 10 }, precision: 0, maxTicksLimit: 4 } },
      x: {
        ...baseOptions().scales.x,
        ticks: { color: '#6b7280', font: { size: 10 }, maxTicksLimit: 6, maxRotation: 0 },
      },
    },
  }),
)

// Collection health: what share of everything raised this month has settled.
const collectedShare = computed(() => {
  if (!payments.value) return 0
  const raised = payments.value.collected_this_month + payments.value.outstanding_amount
  return raised > 0 ? (payments.value.collected_this_month / raised) * 100 : 0
})

const overdueShare = computed(() => {
  if (!payments.value?.outstanding_amount) return 0
  return (payments.value.overdue_amount / payments.value.outstanding_amount) * 100
})

onMounted(() => {
  if (canSeeFigures.value) load()
})
</script>

<template>
  <div class="pf-dash">
    <!-- Hero doubles as the filter row: one window control, scoping every
         counter figure on the screen. -->
    <header class="hero">
      <div class="hero-text">
        <p class="eyebrow">Platform operations console</p>
        <h1>Welcome, {{ operator?.name }}</h1>
        <div class="roles">
          <span v-for="role in operator?.roles" :key="role" class="role-chip">{{ role }}</span>
        </div>
      </div>
      <div v-if="canSeeFigures" class="hero-controls">
        <SelectButton v-model="days" :options="windowOptions" optionLabel="label" optionValue="value"
          :allowEmpty="false" size="small" @update:modelValue="load()" />
        <Button label="Refresh" icon="pi pi-refresh" size="small" text :loading="loading" @click="load(true)" />
        <span v-if="data" class="as-of">as of {{ data.generated_at.slice(11, 16) }}</span>
      </div>
    </header>

    <template v-if="canSeeFigures">
      <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Loading platform figures…</div>

      <!-- Held at reduced opacity while refetching, so a window change never
           flashes a skeleton or jumps the layout. -->
      <div v-else-if="data" :class="{ stale: loading }">
        <section class="cards">
          <component :is="c.to && auth.can(c.permission) ? 'button' : 'div'" v-for="c in cards" :key="c.key"
            class="stat" :class="{ clickable: c.to && auth.can(c.permission) }" @click="openCard(c)">
            <span class="stat-icon" :class="c.tint"><i :class="c.icon" /></span>
            <span class="stat-label">{{ c.label }}</span>
            <span class="stat-value">{{ c.value }}</span>
            <span class="stat-note">{{ c.note }}</span>
            <span v-if="c.delta" class="stat-delta" :class="c.delta.tone">{{ c.delta.text }}</span>
          </component>
        </section>

        <section class="charts">
          <!-- Revenue: 12 months of what actually settled. -->
          <figure v-if="sections.revenue && revenue" class="panel span-2">
            <figcaption>
              <span class="panel-title">Net collected per month</span>
              <span class="panel-sub">Last 12 months, refunds deducted · {{ currency }}</span>
            </figcaption>
            <div class="plot"><Chart type="bar" :data="revenueChart" :options="revenueOptions" /></div>
            <details class="twin">
              <summary>Figures</summary>
              <table>
                <thead><tr><th>Month</th><th>Gross</th><th>Net</th></tr></thead>
                <tbody>
                  <tr v-for="m in trend" :key="m.month">
                    <td>{{ m.label }}</td>
                    <td class="num">{{ fmtMoney(m.gross, currency) }}</td>
                    <td class="num strong">{{ fmtMoney(m.net, currency) }}</td>
                  </tr>
                </tbody>
              </table>
            </details>
          </figure>
          <!-- A withheld block keeps the width of the panel it replaces, so the
               grid tiles the same for every role. -->
          <div v-else class="panel locked span-2">
            <span class="panel-title">Revenue</span>
            <p class="locked-note"><i class="pi pi-lock" /> Your roles do not include revenue access.</p>
          </div>

          <!-- Lifecycle mix. The four buckets partition the whole base, so the
               ring is a genuine part-to-whole and the centre carries the total. -->
          <figure class="panel">
            <figcaption>
              <span class="panel-title">Tenant lifecycle</span>
              <span class="panel-sub">Every workspace sits in exactly one bucket</span>
            </figcaption>
            <div class="ring">
              <div class="ring-plot">
                <Chart type="doughnut" :data="mixChart" :options="mixOptions" />
                <div class="ring-centre">
                  <strong>{{ fmtNumber(tenants.total) }}</strong>
                  <span>tenants</span>
                </div>
              </div>
              <ul class="mix-legend">
                <li v-for="row in mixRows" :key="row.key">
                  <span class="swatch" :style="{ background: row.color }" />
                  <span class="mix-label">{{ row.label }}</span>
                  <span class="num">{{ fmtNumber(row.value) }}</span>
                  <span class="mix-share">{{ fmtPct(row.share, 0) }}</span>
                </li>
              </ul>
            </div>
            <p class="foot-note">
              <i class="pi pi-info-circle" />
              {{ fmtNumber(tenants.suspended) }} suspended and {{ fmtNumber(tenants.past_due) }} past due sit
              <em>across</em> these buckets — a lock and a lapse are separate states.
            </p>
          </figure>

          <!-- Collections: meters rather than a chart — two ratios and their
               amounts are the whole story. -->
          <figure v-if="sections.payments && payments" class="panel">
            <figcaption>
              <span class="panel-title">Collections this month</span>
              <span class="panel-sub">Settled against what is still owed</span>
            </figcaption>
            <div class="meters">
              <div class="meter-row">
                <div class="meter-head">
                  <span>Settled</span>
                  <strong class="num">{{ fmtMoney(payments.collected_this_month, currency) }}</strong>
                </div>
                <div class="meter"><span :style="{ width: `${collectedShare}%`, background: SERIES[2] }" /></div>
                <span class="foot-note">{{ fmtPct(collectedShare, 0) }} of everything raised · {{ fmtNumber(payments.count_this_month) }} invoices</span>
              </div>
              <div class="meter-row">
                <div class="meter-head">
                  <span>Overdue share of arrears</span>
                  <strong class="num">{{ fmtMoney(payments.overdue_amount, currency) }}</strong>
                </div>
                <div class="meter"><span :style="{ width: `${overdueShare}%`, background: overdueShare > 0 ? '#b91c1c' : SERIES[1] }" /></div>
                <span class="foot-note">
                  of {{ fmtMoney(payments.outstanding_amount, currency) }} outstanding ·
                  {{ fmtNumber(payments.overdue_count) }} of {{ fmtNumber(payments.outstanding_count) }} invoices
                </span>
              </div>
              <div v-if="payments.refunded_this_month" class="meter-row">
                <div class="meter-head">
                  <span>Refunded this month</span>
                  <strong class="num">{{ fmtMoney(payments.refunded_this_month, currency) }}</strong>
                </div>
              </div>
            </div>
          </figure>
          <div v-else class="panel locked">
            <span class="panel-title">Collections</span>
            <p class="locked-note"><i class="pi pi-lock" /> Your roles do not include payment access.</p>
          </div>

          <!-- Movement: the two flows that move every other number. -->
          <figure class="panel span-2">
            <figcaption>
              <span class="panel-title">Customer movement</span>
              <span class="panel-sub">New workspaces against cancellations, 12 months</span>
            </figcaption>
            <ul class="legend">
              <li><span class="swatch" :style="{ background: SERIES[2] }" />New tenants</li>
              <li><span class="swatch" :style="{ background: SERIES[1] }" />Cancellations</li>
            </ul>
            <div class="plot short"><Chart type="bar" :data="movementChart" :options="countOptions" /></div>
            <details class="twin">
              <summary>Figures</summary>
              <table>
                <thead><tr><th>Month</th><th>New</th><th>Cancelled</th></tr></thead>
                <tbody>
                  <tr v-for="m in movement" :key="m.month">
                    <td>{{ m.label }}</td>
                    <td class="num">{{ m.new_tenants }}</td>
                    <td class="num">{{ m.cancellations }}</td>
                  </tr>
                </tbody>
              </table>
            </details>
          </figure>

          <!-- Consumption. Two counters three orders of magnitude apart, so one
               small chart each rather than two scales on one plot. -->
          <figure v-if="sections.usage && usage" class="panel span-2">
            <figcaption>
              <span class="panel-title">Platform consumption</span>
              <span class="panel-sub">Counters over the last {{ days }} days · storage is the latest reading</span>
            </figcaption>
            <div class="usage-grid">
              <div class="mini">
                <div class="mini-head">
                  <span class="mini-label">API calls</span>
                  <strong class="num">{{ fmtNumber(usage.api_calls) }}</strong>
                </div>
                <div class="plot spark"><Chart type="line" :data="apiChart" :options="sparkOptions" /></div>
              </div>
              <div class="mini">
                <div class="mini-head">
                  <span class="mini-label">AI calls</span>
                  <strong class="num">{{ fmtNumber(usage.ai_calls) }}</strong>
                </div>
                <div class="plot spark"><Chart type="line" :data="aiChart" :options="sparkOptions" /></div>
              </div>
              <div class="mini stats">
                <div class="mini-head"><span class="mini-label">Storage</span></div>
                <strong class="big">{{ fmtBytes(usage.storage_bytes) }}</strong>
                <span class="foot-note">across every tenant folder</span>
                <div class="mini-head spaced">
                  <span class="mini-label">Tenant data</span>
                </div>
                <span class="pair">
                  <strong class="num">{{ fmtNumber(usage.products) }}</strong> products ·
                  <strong class="num">{{ fmtNumber(usage.customers) }}</strong> customers
                </span>
              </div>
            </div>
          </figure>
          <div v-else class="panel locked span-2">
            <span class="panel-title">Usage</span>
            <p class="locked-note"><i class="pi pi-lock" /> Your roles do not include usage access.</p>
          </div>

          <div v-if="sections.payments && payments" class="panel span-2">
            <div class="panel-head-row">
              <div>
                <span class="panel-title">Latest payments</span>
                <span class="panel-sub">Most recent settled subscription invoices</span>
              </div>
              <Button v-if="auth.can('invoices.view')" label="All invoices" icon="pi pi-arrow-right" iconPos="right"
                size="small" text @click="router.push('/platform/billing')" />
            </div>
            <DataTable :value="payments.recent" class="p-datatable-sm" dataKey="id">
              <Column field="number" header="Invoice" style="width: 10rem" />
              <Column field="tenant_name" header="Tenant" />
              <Column header="Amount" style="width: 9rem">
                <template #body="{ data: row }"><span class="num">{{ fmtMoney(row.amount, currency) }}</span></template>
              </Column>
              <Column header="Method" style="width: 8rem">
                <template #body="{ data: row }"><span class="muted">{{ row.method || '—' }}</span></template>
              </Column>
              <Column header="Paid" style="width: 7rem">
                <template #body="{ data: row }">{{ fmtDate(row.paid_at) }}</template>
              </Column>
              <Column header="Status" style="width: 7rem">
                <template #body="{ data: row }">
                  <Tag :value="row.status" :severity="invoiceSeverity[row.status] || 'secondary'" />
                </template>
              </Column>
              <template #empty>No payments recorded yet.</template>
            </DataTable>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.pf-dash { padding-bottom: 1.5rem; }

/* ---- Hero ---------------------------------------------------------------- */
.hero {
  display: flex; align-items: flex-end; justify-content: space-between; gap: 1.25rem; flex-wrap: wrap;
  background: linear-gradient(135deg, #2c4a5e 0%, #1e3547 60%, #24404f 100%);
  border-radius: 16px; padding: 1.4rem 1.6rem; margin-bottom: 1.1rem; color: #fff;
}
.eyebrow { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.09em; color: #a8c2d4; font-weight: 600; }
/* style.css colours every h1 with --graphite, and a direct rule beats
   inheritance from .hero — so the heading needs its own colour or it renders
   dark ink on the dark gradient. */
.hero h1 { font-size: 1.5rem; font-weight: 700; margin: 0.2rem 0 0.55rem; color: #fff; }
.roles { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.role-chip {
  font-size: 0.7rem; font-weight: 600; text-transform: capitalize; padding: 0.15rem 0.55rem;
  border-radius: 999px; background: rgba(232, 163, 61, 0.18); color: #f0c78a; border: 1px solid rgba(232, 163, 61, 0.35);
}
.hero-controls { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.hero-controls :deep(.p-button.p-button-text) { color: #dce7ef; }
.hero-controls :deep(.p-button.p-button-text:hover) { background: rgba(255, 255, 255, 0.12); color: #fff; }
.hero-controls :deep(.p-selectbutton) { background: rgba(255, 255, 255, 0.1); border-radius: 8px; }
.hero-controls :deep(.p-togglebutton) { background: transparent; border-color: transparent; color: #cfe0ea; }
.hero-controls :deep(.p-togglebutton-checked) { background: #fff; color: #1e3547; }
.as-of { font-size: 0.72rem; color: #a8c2d4; font-variant-numeric: tabular-nums; }

.loading { color: #6b7280; font-size: 0.9rem; padding: 2.5rem 0; display: flex; align-items: center; gap: 0.5rem; }
.stale { opacity: 0.55; transition: opacity 0.15s; }

/* ---- Headline cards ------------------------------------------------------ */
.cards {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(212px, 1fr)); gap: 0.85rem; margin-bottom: 0.95rem;
}
.stat {
  display: flex; flex-direction: column; gap: 0.1rem; text-align: left; width: 100%;
  background: #fff; border: 1px solid #e9ecef; border-radius: 14px; padding: 1rem 1.1rem;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); font: inherit; color: inherit; position: relative;
}
.stat.clickable { cursor: pointer; transition: box-shadow 0.15s, transform 0.15s, border-color 0.15s; }
.stat.clickable:hover { box-shadow: 0 8px 22px rgba(16, 24, 40, 0.09); transform: translateY(-2px); border-color: #dbe3ea; }
.stat-icon {
  width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center;
  font-size: 0.95rem; margin-bottom: 0.5rem;
}
.stat-icon.blue { background: #eaf2fc; color: #2a78d6; }
.stat-icon.green { background: #e9f5ee; color: #2f7f56; }
.stat-icon.amber { background: #fdf3e3; color: #9a6412; }
.stat-icon.purple { background: #f4ecfb; color: #8b47bb; }
.stat-icon.red { background: #fdeceb; color: #b91c1c; }
.stat-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }
/* Proportional figures on the headline number; tabular only where digits align. */
.stat-value { font-size: 1.55rem; font-weight: 700; line-height: 1.2; color: #111827; overflow-wrap: anywhere; }
.stat-note { font-size: 0.75rem; color: #6b7280; margin-top: 0.1rem; }
.stat-delta {
  font-size: 0.72rem; font-weight: 600; margin-top: 0.4rem; align-self: flex-start;
  padding: 0.1rem 0.45rem; border-radius: 999px;
}
.stat-delta.up { background: #e9f5ee; color: #15803d; }
.stat-delta.down { background: #fdeceb; color: #b91c1c; }
.stat-delta.flat { background: #f3f4f6; color: #6b7280; }

/* ---- Chart panels -------------------------------------------------------- */
/* Panels tile in a fixed order that pairs the 1-wide cards; `dense` is the
   safety net for a role whose withheld blocks change that order. */
.charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; grid-auto-flow: row dense; }
.panel {
  background: #fff; border: 1px solid #e9ecef; border-radius: 14px; padding: 1.05rem 1.15rem 1.1rem;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); margin: 0; min-width: 0;
}
.panel figcaption, .panel-head-row > div { display: flex; flex-direction: column; line-height: 1.3; }
.panel-head-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.panel-title { font-weight: 600; font-size: 0.95rem; color: #111827; }
.panel-sub { font-size: 0.76rem; color: #6b7280; }
.plot { height: 250px; margin-top: 0.7rem; }
.plot.short { height: 218px; }
.plot.spark { height: 118px; margin-top: 0.35rem; }
.panel.locked { border-style: dashed; background: #fbfbfc; display: flex; flex-direction: column; gap: 0.4rem; }
.locked-note { font-size: 0.8rem; color: #9ca3af; display: flex; align-items: center; gap: 0.4rem; }

/* Identity is never colour-alone: every multi-series figure carries a legend. */
.legend, .mix-legend { list-style: none; margin: 0; padding: 0; }
.legend { display: flex; flex-wrap: wrap; gap: 0.9rem; margin-top: 0.45rem; font-size: 0.78rem; color: #4b5563; }
.legend li { display: flex; align-items: center; gap: 0.4rem; }
.swatch { width: 10px; height: 10px; border-radius: 3px; display: inline-block; flex-shrink: 0; }

/* ---- Lifecycle ring ------------------------------------------------------ */
.ring { display: flex; align-items: center; gap: 1.1rem; margin-top: 0.7rem; flex-wrap: wrap; }
.ring-plot { position: relative; width: 156px; height: 156px; flex-shrink: 0; }
.ring-centre {
  position: absolute; inset: 0; display: grid; place-content: center; text-align: center; pointer-events: none;
}
.ring-centre strong { font-size: 1.5rem; font-weight: 700; color: #111827; line-height: 1.1; }
.ring-centre span { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; }
.mix-legend { flex: 1; min-width: 172px; display: flex; flex-direction: column; gap: 0.4rem; }
.mix-legend li {
  display: grid; grid-template-columns: 10px 1fr auto auto; align-items: center; gap: 0.5rem;
  font-size: 0.82rem; color: #374151; padding-bottom: 0.35rem; border-bottom: 1px solid #f1f3f5;
}
.mix-legend li:last-child { border-bottom: 0; padding-bottom: 0; }
.mix-label { color: #4b5563; }
.mix-share { color: #9ca3af; font-size: 0.75rem; min-width: 2.6rem; text-align: right; }

/* ---- Consumption --------------------------------------------------------- */
.usage-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-top: 0.6rem; }
.mini { min-width: 0; }
.mini-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; }
.mini-head.spaced { margin-top: 0.85rem; }
.mini-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }
.mini-head strong { font-size: 1.05rem; color: #111827; }
.mini.stats { display: flex; flex-direction: column; }
.mini .big { font-size: 1.45rem; font-weight: 700; color: #111827; line-height: 1.2; }
.pair { font-size: 0.85rem; color: #4b5563; }
.pair strong { color: #111827; }

/* ---- Meters -------------------------------------------------------------- */
.meters { display: flex; flex-direction: column; gap: 1rem; margin-top: 0.85rem; }
.meter-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; font-size: 0.83rem; color: #4b5563; }
.meter-head strong { color: #111827; font-size: 0.95rem; }
.meter { height: 8px; border-radius: 4px; background: #eef0f2; overflow: hidden; margin: 0.35rem 0 0.3rem; }
.meter > span { display: block; height: 100%; border-radius: 4px; }

/* ---- Table twins & shared text ------------------------------------------ */
.twin { margin-top: 0.7rem; }
.twin summary { font-size: 0.76rem; color: #6b7280; cursor: pointer; }
.twin summary:hover { color: #2a78d6; }
.twin table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; font-size: 0.78rem; }
.twin th, .twin td { text-align: left; padding: 0.28rem 0.4rem; border-bottom: 1px solid #f1f3f5; }
.twin th { color: #6b7280; font-weight: 600; }
.twin td.num, .twin th:not(:first-child) { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.strong { font-weight: 600; }
.muted { color: #9ca3af; }
.foot-note { font-size: 0.75rem; color: #6b7280; display: flex; align-items: baseline; gap: 0.35rem; margin-top: 0.5rem; }
.foot-note em { font-style: italic; }

@media (max-width: 1100px) {
  .charts { grid-template-columns: minmax(0, 1fr); }
  .span-2 { grid-column: auto; }
}
@media (min-width: 1101px) {
  .span-2 { grid-column: span 2; }
}
@media (max-width: 640px) {
  .hero { padding: 1.15rem 1.15rem; border-radius: 14px; }
  .plot { height: 220px; }
}
</style>
