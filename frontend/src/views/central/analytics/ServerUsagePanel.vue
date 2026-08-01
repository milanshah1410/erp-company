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
import { SERIES, baseOptions, fmtBytes, fmtNumber } from './shared'

const toast = useToast()
const loading = ref(false)
const data = ref<any>(null)

async function load() {
  loading.value = true
  try {
    const res = await centralHttp.get('/analytics/server-usage')
    data.value = res.data.data
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Could not load server usage', life: 4000 })
  } finally {
    loading.value = false
  }
}

const activity = computed<any[]>(() => data.value?.activity_30d ?? [])
const tables = computed<any[]>(() => data.value?.database?.tables ?? [])
const tenantRows = computed<any[]>(() => data.value?.tenants?.rows ?? [])

const activityChart = computed(() => ({
  labels: activity.value.map((d) => d.day.slice(5)),
  datasets: [
    {
      data: activity.value.map((d) => d.events),
      backgroundColor: SERIES[0],
      hoverBackgroundColor: '#256abf',
      borderRadius: 3,
      maxBarThickness: 14,
    },
  ],
}))

const activityOptions = computed(() => {
  const base = baseOptions()
  return {
    ...base,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        callbacks: { label: (ctx: any) => ` ${fmtNumber(ctx.parsed.y)} events` },
      },
    },
    scales: {
      ...base.scales,
      y: { ...base.scales.y, ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 } },
    },
  }
})

const tableChart = computed(() => ({
  labels: tables.value.map((t) => t.name),
  datasets: [
    {
      data: tables.value.map((t) => t.size_bytes),
      backgroundColor: SERIES[3],
      hoverBackgroundColor: '#8f47bd',
      borderRadius: 4,
      maxBarThickness: 18,
    },
  ],
}))

const tableOptions = computed(() => ({
  ...baseOptions(),
  indexAxis: 'y',
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: '#1f2937',
      padding: 10,
      callbacks: { label: (ctx: any) => ` ${fmtBytes(ctx.parsed.x)}` },
    },
  },
  scales: {
    x: {
      beginAtZero: true,
      border: { display: false },
      grid: { color: '#eceef1' },
      ticks: { color: '#6b7280', font: { size: 11 }, callback: (v: any) => fmtBytes(v) },
    },
    y: { border: { display: false }, grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } },
  },
}))

const diskUsedPct = computed(() => {
  const total = data.value?.runtime?.disk_total_bytes
  const free = data.value?.runtime?.disk_free_bytes
  if (!total || free === null || free === undefined) return null
  return Math.round(((total - free) / total) * 100)
})

const activityTotal = computed(() => activity.value.reduce((sum, d) => sum + d.events, 0))

onMounted(load)
</script>

<template>
  <div class="pf-analytics">
    <div class="panel-head">
      <p class="lede">
        What the platform is consuming and which tenants are consuming it. Row counts stand in
        for per-tenant footprint — a shared-schema database has no per-tenant byte figure.
      </p>
      <Button label="Refresh" icon="pi pi-refresh" size="small" outlined :loading="loading" @click="load" />
    </div>

    <div v-if="loading && !data" class="loading"><i class="pi pi-spin pi-spinner" /> Measuring server usage…</div>

    <template v-else-if="data">
      <Message v-if="data.database?.error" severity="warn" :closable="false" class="mb">
        Database size is unavailable on this deployment ({{ data.database.error }}). Everything else below is live.
      </Message>
      <Message v-if="data.storage?.error" severity="warn" :closable="false" class="mb">
        Tenant storage could not be walked ({{ data.storage.error }}).
      </Message>

      <div class="tiles">
        <div class="tile">
          <span class="tile-label">Database</span>
          <span class="tile-value">{{ fmtBytes(data.database?.size_bytes) }}</span>
          <span class="tile-note">{{ fmtNumber(data.database?.table_count) }} tables · {{ data.database?.driver }}</span>
        </div>
        <div class="tile">
          <span class="tile-label">Tenant storage</span>
          <span class="tile-value">{{ fmtBytes(data.storage?.total_bytes) }}</span>
          <span class="tile-note">{{ fmtNumber(data.storage?.tenant_folders) }} folders on “{{ data.storage?.disk }}”</span>
        </div>
        <div class="tile">
          <span class="tile-label">Disk</span>
          <span class="tile-value">{{ diskUsedPct === null ? '—' : `${diskUsedPct}%` }}</span>
          <span class="tile-note">
            {{ fmtBytes(data.runtime?.disk_free_bytes) }} free of {{ fmtBytes(data.runtime?.disk_total_bytes) }}
          </span>
        </div>
        <div class="tile">
          <span class="tile-label">Queue</span>
          <span class="tile-value">{{ fmtNumber(data.queue?.pending) }}</span>
          <span class="tile-note" :class="{ danger: data.queue?.failed > 0 }">
            {{ fmtNumber(data.queue?.failed) }} failed · {{ data.queue?.driver }} driver
          </span>
        </div>
        <div class="tile">
          <span class="tile-label">Activity (30 days)</span>
          <span class="tile-value">{{ fmtNumber(activityTotal) }}</span>
          <span class="tile-note">logged write events</span>
        </div>
      </div>

      <div class="figures">
        <figure class="figure">
          <figcaption>
            <span class="fig-title">Platform activity</span>
            <span class="fig-sub">Logged write events per day, last 30 days</span>
          </figcaption>
          <div class="chart-wrap"><Chart type="bar" :data="activityChart" :options="activityOptions" /></div>
        </figure>

        <figure v-if="tables.length" class="figure">
          <figcaption>
            <span class="fig-title">Heaviest tables</span>
            <span class="fig-sub">Data + index size</span>
          </figcaption>
          <div class="chart-wrap tall"><Chart type="bar" :data="tableChart" :options="tableOptions" /></div>
        </figure>
      </div>

      <h3 class="sub-heading">Runtime</h3>
      <div class="controls">
        <Tag :value="`PHP ${data.runtime?.php_version}`" severity="secondary" />
        <Tag :value="`Cache: ${data.runtime?.cache_driver}`" severity="secondary" />
        <Tag :value="`Queue: ${data.queue?.driver}`" severity="secondary" />
        <Tag :value="`Search: ${data.runtime?.search_driver ?? 'none'}`" severity="secondary" />
        <span class="muted">measured {{ data.measured_at }}</span>
      </div>

      <template v-if="data.storage?.top?.length">
        <h3 class="sub-heading">Largest tenant storage folders</h3>
        <DataTable :value="data.storage.top" class="p-datatable-sm" dataKey="tenant_id">
          <Column field="tenant_id" header="Tenant #" style="width: 8rem" />
          <Column header="Stored">
            <template #body="{ data: row }">{{ fmtBytes(row.bytes) }}</template>
          </Column>
        </DataTable>
      </template>

      <h3 class="sub-heading">Per-tenant data footprint</h3>
      <DataTable :value="tenantRows" class="p-datatable-sm" dataKey="tenant_id" paginator :rows="10">
        <Column field="tenant_name" header="Tenant" sortable />
        <Column field="status" header="Status" style="width: 8rem">
          <template #body="{ data: row }">
            <Tag :value="row.status" :severity="row.status === 'active' ? 'success' : 'secondary'" />
          </template>
        </Column>
        <Column field="users" header="Users" sortable style="width: 7rem" />
        <Column field="products" header="Products" sortable style="width: 8rem" />
        <Column field="invoices" header="Invoices" sortable style="width: 8rem" />
        <Column field="tickets" header="Tickets" sortable style="width: 7.5rem" />
        <template #empty>No tenants yet.</template>
        <template #footer>
          <span class="muted">
            Totals — {{ fmtNumber(data.tenants?.totals?.users) }} users ·
            {{ fmtNumber(data.tenants?.totals?.products) }} products ·
            {{ fmtNumber(data.tenants?.totals?.invoices) }} invoices
          </span>
        </template>
      </DataTable>
    </template>
  </div>
</template>

<style scoped>
.mb { margin-bottom: 0.75rem; }
</style>
