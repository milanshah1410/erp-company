<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const grandTotal = ref(0)
const page = ref(1)
const perPage = ref(20)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const { exporting, exportAs } = useReportExport('/reports/sales', 'sales-report')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/sales', { params: { ...params(), page: page.value, per_page: perPage.value } })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
    grandTotal.value = data.meta?.grand_total ?? 0
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

function money(v: any) {
  return formatCurrency(v)
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="page = 1; load()" @export="doExport">
      <span class="grand-total">Total: {{ money(grandTotal) }}</span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="so_number" @page="onPage" class="p-datatable-sm">
      <Column field="so_number" header="SO Number" />
      <Column field="customer" header="Customer" />
      <Column field="warehouse" header="Warehouse" />
      <Column field="order_date" header="Order Date" />
      <Column header="Status"><template #body="{ data }"><Tag :value="data.status" /></template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <template #empty>No sales orders in the selected range.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.grand-total { font-weight: 600; color: #0f172a; }
</style>
