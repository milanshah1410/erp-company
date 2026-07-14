<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import SelectButton from 'primevue/selectbutton'
import ReportToolbar from '../components/reports/ReportToolbar.vue'
import http from '../api/http'
import { useReportExport } from '../composables/useReportExport'
import { formatCurrency } from '../utils/currency'

const route = useRoute()

// Two views over the same performance data: an aggregated per-tailor scorecard
// and the detailed rejected-piece list. Deep-linkable via ?tab=rejections.
const tab = ref<'performance' | 'rejections'>(route.query.tab === 'rejections' ? 'rejections' : 'performance')
const tabOptions = [
  { label: 'Performance', value: 'performance' },
  { label: 'Rejected Pieces', value: 'rejections' },
]

const endpoint = computed(() => (tab.value === 'rejections' ? '/reports/tailor-rejections' : '/reports/tailor-performance'))

const rows = ref<any[]>([])
const loading = ref(false)
const meta = ref<any>({})
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const contractors = ref<{ label: string; value: number }[]>([])
const tailors = ref<{ label: string; value: number }[]>([])
const filterContractor = ref<number | null>(null)
const filterTailor = ref<number | null>(null)

const { exporting, exportAs } = useReportExport(() => endpoint.value, () => tab.value === 'rejections' ? 'tailor-rejections' : 'tailor-performance')

function money(v: any) {
  return formatCurrency(v)
}

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return {
    date_from: toDateParam(dateFrom.value),
    date_to: toDateParam(dateTo.value),
    contractor_id: tab.value === 'performance' ? (filterContractor.value || undefined) : undefined,
    tailor_id: filterTailor.value || undefined,
  }
}

async function loadOptions() {
  const [c, t] = await Promise.all([
    http.get('/contractors', { params: { per_page: 100, is_active: true } }),
    http.get('/tailors', { params: { per_page: 100, is_active: true } }),
  ])
  contractors.value = (c.data.data ?? c.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
  tailors.value = (t.data.data ?? t.data).map((x: any) => ({ label: `${x.code} — ${x.name}`, value: x.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get(endpoint.value, { params: params() })
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

function switchTab() {
  rows.value = []
  load()
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

function actionSeverity(action: string) {
  return { rework: 'warn', scrap: 'danger' }[action] || 'secondary'
}

onMounted(async () => {
  await loadOptions()
  await load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Tailor Performance</h1>
      <SelectButton v-model="tab" :options="tabOptions" optionLabel="label" optionValue="value"
        :allowEmpty="false" @change="switchTab" />
    </div>

    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting"
      @refresh="load" @export="doExport">
      <Select v-if="tab === 'performance'" v-model="filterContractor" :options="contractors" optionLabel="label"
        optionValue="value" placeholder="All contractors" showClear filter class="flt" @change="load" />
      <Select v-model="filterTailor" :options="tailors" optionLabel="label" optionValue="value"
        placeholder="All tailors" showClear filter class="flt" @change="load" />
    </ReportToolbar>

    <!-- Performance scorecard -->
    <DataTable v-if="tab === 'performance'" :value="rows" :loading="loading" dataKey="tailor_id" class="p-datatable-sm">
      <Column field="code" header="Code" />
      <Column field="tailor" header="Tailor" />
      <Column field="issued_qty" header="Issued" />
      <Column field="completed_qty" header="Completed" />
      <Column header="Rejected">
        <template #body="{ data }"><span :class="{ bad: data.rejected_qty > 0 }">{{ data.rejected_qty }}</span></template>
      </Column>
      <Column field="pending_qty" header="Pending" />
      <Column header="Reject %">
        <template #body="{ data }">{{ data.reject_pct }}%</template>
      </Column>
      <Column header="Efficiency">
        <template #body="{ data }">{{ data.efficiency_pct }}%</template>
      </Column>
      <Column header="On-Time">
        <template #body="{ data }">{{ data.on_time_pct === null ? '—' : data.on_time_pct + '%' }}</template>
      </Column>
      <Column field="alterations" header="Alt." />
      <Column header="Earnings">
        <template #body="{ data }">{{ money(data.earned) }}</template>
      </Column>
      <Column header="Bonus">
        <template #body="{ data }">{{ money(data.bonus) }}</template>
      </Column>
      <Column header="Total">
        <template #body="{ data }"><strong>{{ money(data.total_earned) }}</strong></template>
      </Column>
      <template #empty>No performance data for the selected filters.</template>
      <template #footer>
        <div class="totals" v-if="rows.length">
          Pieces: <strong>{{ meta.total_pieces ?? 0 }}</strong> ·
          Rejected: <strong>{{ meta.total_rejected ?? 0 }}</strong> ·
          Total earned: <strong>{{ money(meta.total_earned ?? 0) }}</strong>
        </div>
      </template>
    </DataTable>

    <!-- Rejected pieces detail -->
    <DataTable v-else :value="rows" :loading="loading" dataKey="work_number" class="p-datatable-sm">
      <Column field="date" header="Date" />
      <Column field="work_number" header="Work #" />
      <Column field="tailor" header="Tailor" />
      <Column field="product" header="Product" />
      <Column field="accepted_qty" header="Accepted" />
      <Column header="Rejected">
        <template #body="{ data }"><span class="bad">{{ data.rejected_qty }}</span></template>
      </Column>
      <Column header="Action">
        <template #body="{ data }"><Tag :value="data.action" :severity="actionSeverity(data.action)" /></template>
      </Column>
      <Column field="reason" header="Reason" />
      <template #empty>No rejected pieces in the selected range.</template>
      <template #footer>
        <div class="totals" v-if="rows.length">Total rejected: <strong>{{ meta.total_rejected ?? 0 }}</strong></div>
      </template>
    </DataTable>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}
.header h1 {
  font-size: 1.5rem;
  font-weight: 600;
}
.flt {
  min-width: 16rem;
}
.totals {
  text-align: right;
  color: #334155;
}
.bad {
  color: #dc2626;
  font-weight: 600;
}
</style>
