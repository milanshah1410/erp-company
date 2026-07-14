<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const loading = ref(false)
const meta = ref<any>({})

const { exporting, exportAs } = useReportExport('/reports/payables', 'payables')

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/payables')
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format)
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar :exporting="exporting" :show-date-range="false" @refresh="load" @export="doExport">
      <span class="totals">Total Outstanding: {{ money(meta.total_outstanding) }}</span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" dataKey="code" class="p-datatable-sm">
      <Column field="code" header="Code" />
      <Column field="supplier" header="Supplier" />
      <Column field="gst_number" header="GSTIN" />
      <Column header="0-30 Days"><template #body="{ data }">{{ money(data.bucket_0_30) }}</template></Column>
      <Column header="31-60 Days"><template #body="{ data }">{{ money(data.bucket_31_60) }}</template></Column>
      <Column header="61-90 Days"><template #body="{ data }">{{ money(data.bucket_61_90) }}</template></Column>
      <Column header="90+ Days"><template #body="{ data }">{{ money(data.bucket_90_plus) }}</template></Column>
      <Column header="Total Outstanding">
        <template #body="{ data }"><strong>{{ money(data.total_outstanding) }}</strong></template>
      </Column>
      <template #empty>No pending supplier payments.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.totals { font-weight: 600; color: #0f172a; }
</style>
