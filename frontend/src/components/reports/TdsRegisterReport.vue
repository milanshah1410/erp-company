<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const { exporting, exportAs } = useReportExport('/reports/tds-register', 'tds-register')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}
function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}
function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/tds-register', { params: params() })
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

const totalTds = computed(() => money(meta.value.total_tds ?? 0))

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="load" @export="doExport" />

    <DataTable :value="rows" :loading="loading" class="p-datatable-sm" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100]">
      <Column field="date" header="Date" />
      <Column field="section" header="Section"><template #body="{ data }">{{ data.section || '—' }}</template></Column>
      <Column field="party" header="Party"><template #body="{ data }">{{ data.party || '—' }}</template></Column>
      <Column field="document" header="Document" />
      <Column header="Base Amount" style="text-align: right"><template #body="{ data }">{{ money(data.base_amount) }}</template></Column>
      <Column header="Rate %"><template #body="{ data }">{{ data.rate }}%</template></Column>
      <Column header="TDS Amount" style="text-align: right"><template #body="{ data }"><strong>{{ money(data.tds_amount) }}</strong></template></Column>
      <template #empty>No TDS deducted in the selected range.</template>
    </DataTable>
    <p class="note">Total TDS deducted: {{ totalTds }} across {{ meta.count ?? 0 }} entries (purchase bills, expenses &amp; broker commission).</p>
  </div>
</template>

<style scoped>
.note { color: #6b7280; font-size: 0.8rem; margin-top: 0.75rem; }
</style>
