<script setup lang="ts">
/**
 * Machine utilisation — available hours against recorded downtime.
 *
 * Available hours are asked for rather than assumed, because the ERP does not
 * keep a shift calendar: a single-shift unit and a three-shift one would
 * otherwise share a denominator and get two very different truths. The default
 * is 8 hours a day across the window, and the screen says so out loud so nobody
 * reads an assumed figure as a measured one.
 *
 * Downtime cost is the number that makes this actionable: hours lost × the
 * machine's own hourly rate, which is why rating the plant matters.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import ProgressBar from 'primevue/progressbar'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)

const today = new Date()
const monthStart = new Date(today.getFullYear(), today.getMonth(), 1)

const from = ref<Date>(monthStart)
const to = ref<Date>(today)
const availableHours = ref<number | null>(null)

function iso(d: Date) {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

function money(v: any) {
  return formatCurrency(v)
}

/** Below 60% is a machine that is idle or broken more than it works. */
function severity(percent: number | null) {
  if (percent === null || percent === undefined) return 'secondary'
  if (percent >= 85) return 'success'
  if (percent >= 60) return 'warn'
  return 'danger'
}

const totals = computed(() => {
  const down = rows.value.reduce((s, r) => s + (Number(r.downtime_hours) || 0), 0)
  const cost = rows.value.reduce((s, r) => s + (Number(r.downtime_cost) || 0), 0)
  return { downtime_hours: Math.round(down * 100) / 100, downtime_cost: Math.round(cost * 100) / 100 }
})

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/machines/utilisation', {
      params: {
        from: iso(from.value),
        to: iso(to.value),
        available_hours: availableHours.value ?? undefined,
      },
    })
    rows.value = data.data
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Machine Utilisation')"
      :subtitle="pt('How much of the available time each machine actually ran, and what the stops cost')"
    />

    <div class="toolbar">
      <div class="fld">
        <label>{{ pt('From') }}</label>
        <DatePicker v-model="from" date-format="dd/mm/yy" show-icon />
      </div>
      <div class="fld">
        <label>{{ pt('To') }}</label>
        <DatePicker v-model="to" date-format="dd/mm/yy" show-icon />
      </div>
      <div class="fld">
        <label>{{ pt('Available hours') }}</label>
        <InputNumber v-model="availableHours" :min="0" :max-fraction-digits="1" :placeholder="pt('Assumed 8/day')" />
      </div>
      <Button icon="pi pi-refresh" :label="pt('Run')" @click="load" />
      <TableExportButtons
        title="Machine Utilisation"
        filenameBase="machine-utilisation"
        :rows="rows"
        :columns="[
          { key: 'code', label: 'Code' },
          { key: 'name', label: 'Machine' },
          { key: 'available_hours', label: 'Available hrs' },
          { key: 'downtime_hours', label: 'Downtime hrs' },
          { key: 'running_hours', label: 'Running hrs' },
          { key: 'utilisation_percent', label: 'Utilisation %' },
          { key: 'downtime_cost', label: 'Downtime cost' },
        ]"
      />
    </div>

    <div class="tiles">
      <div class="tile">
        <span class="t-label">{{ pt('Machines') }}</span>
        <span class="t-value">{{ rows.length }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Available hours each') }}</span>
        <span class="t-value">
          {{ meta.available_hours ?? '—' }}
          <small v-if="meta.available_hours_source === 'assumed_8h_per_day'">
            {{ pt('assumed 8h/day') }}
          </small>
        </span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Downtime hours') }}</span>
        <span class="t-value bad">{{ totals.downtime_hours }}</span>
      </div>
      <div class="tile">
        <span class="t-label">{{ pt('Cost of downtime') }}</span>
        <span class="t-value bad">{{ money(totals.downtime_cost) }}</span>
      </div>
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="id"
      striped-rows
      responsive-layout="scroll"
      paginator
      :rows="25"
    >
      <Column field="code" :header="pt('Code')" sortable />
      <Column field="name" :header="pt('Machine')" sortable>
        <template #body="{ data }">
          <div>
            <div>{{ data.name }}</div>
            <small class="muted">{{ data.machine_type || '—' }}</small>
          </div>
        </template>
      </Column>
      <Column field="status" :header="pt('Status')">
        <template #body="{ data }">
          <Tag :value="pt(data.status)" :severity="data.status === 'running' ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column field="running_hours" :header="pt('Running hrs')" sortable />
      <Column field="downtime_hours" :header="pt('Downtime hrs')" sortable />
      <Column field="utilisation_percent" :header="pt('Utilisation')" sortable style="min-width: 12rem">
        <template #body="{ data }">
          <div v-if="data.utilisation_percent !== null" class="util">
            <ProgressBar :value="data.utilisation_percent" :show-value="false" style="height: 0.5rem" />
            <Tag :value="`${data.utilisation_percent}%`" :severity="severity(data.utilisation_percent)" />
          </div>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="downtime_cost" :header="pt('Cost of downtime')" sortable>
        <template #body="{ data }">
          <span v-if="data.downtime_cost > 0">{{ money(data.downtime_cost) }}</span>
          <span v-else class="muted" :title="pt('Machine has no hourly cost set')">—</span>
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('No active machines. Utilisation needs a machine master first.') }}
        </div>
      </template>
    </DataTable>

    <p class="footnote">
      {{
        pt(
          'Only closed stops count: a machine still down has no duration yet, so its loss appears once it is back in service.',
        )
      }}
    </p>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.tile { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 10px; padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 0.2rem; }
.t-label { font-size: 0.75rem; color: #6b7280; }
.t-value { font-size: 1.25rem; font-weight: 700; }
.t-value small { font-size: 0.7rem; font-weight: 500; color: #6b7280; display: block; }
.t-value.bad { color: #b91c1c; }
.toolbar { display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.util { display: flex; flex-direction: column; gap: 0.3rem; }
.muted { color: #6b7280; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
.footnote { margin-top: 0.9rem; font-size: 0.72rem; color: #6b7280; }
</style>
