<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'

const rows = ref<any[]>([])
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const { exporting, exportAs } = useReportExport('/reports/stock-movement', 'stock-movement')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/stock-movement', { params: params() })
    rows.value = data.data
  } finally {
    loading.value = false
  }
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="load" @export="doExport" />

    <DataTable :value="rows" :loading="loading" dataKey="sku" class="p-datatable-sm">
      <Column header="Product"><template #body="{ data }">{{ data.product_name }}<br /><small style="color:#6b7280">{{ data.sku }}</small></template></Column>
      <Column field="warehouse" header="Warehouse" />
      <Column header="Total In"><template #body="{ data }"><span class="in">+{{ data.total_in }}</span></template></Column>
      <Column header="Total Out"><template #body="{ data }"><span class="out">-{{ data.total_out }}</span></template></Column>
      <Column field="net_change" header="Net Change" />
      <template #empty>No movement in the selected range.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.in { color: #059669; font-weight: 600; }
.out { color: #dc2626; font-weight: 600; }
</style>
