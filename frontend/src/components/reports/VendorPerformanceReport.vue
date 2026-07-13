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

const { exporting, exportAs } = useReportExport('/reports/vendor-performance', 'vendor-performance')

function money(v: any) {
  return formatCurrency(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/vendor-performance')
    rows.value = data.data
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
    <ReportToolbar :exporting="exporting" :show-date-range="false" @refresh="load" @export="doExport" />

    <DataTable :value="rows" :loading="loading" dataKey="supplier" class="p-datatable-sm">
      <Column field="supplier" header="Supplier" />
      <Column field="total_pos" header="Total POs" />
      <Column header="Total Value"><template #body="{ data }">{{ money(data.total_value) }}</template></Column>
      <Column field="on_time_deliveries" header="On-Time Deliveries" />
      <Column field="tracked_deliveries" header="Tracked Deliveries" />
      <Column header="On-Time Rate">
        <template #body="{ data }">{{ data.on_time_rate !== null ? data.on_time_rate + '%' : '—' }}</template>
      </Column>
      <Column header="Avg Lead Time">
        <template #body="{ data }">{{ data.avg_lead_time_days !== null ? data.avg_lead_time_days + ' days' : '—' }}</template>
      </Column>
      <template #empty>No purchase history yet.</template>
    </DataTable>
  </div>
</template>
