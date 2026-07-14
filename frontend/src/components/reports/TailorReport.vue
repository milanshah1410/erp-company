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

const { exporting, exportAs } = useReportExport('/reports/tailor', 'tailor-report')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/tailor', { params: { ...params(), page: page.value, per_page: perPage.value } })
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
      <span class="grand-total">Total Amount: {{ money(grandTotal) }}</span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="work_number" @page="onPage" class="p-datatable-sm">
      <Column field="work_number" header="Work No" />
      <Column field="tailor" header="Tailor" />
      <Column field="product" header="Product" />
      <Column field="issued_qty" header="Issued" />
      <Column field="completed_qty" header="Completed" />
      <Column field="pending_qty" header="Pending" />
      <Column header="Rate"><template #body="{ data }">{{ money(data.rate) }}</template></Column>
      <Column header="Amount"><template #body="{ data }"><strong>{{ money(data.amount) }}</strong></template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :value="data.status" /></template></Column>
      <Column field="issue_date" header="Issue Date" />
      <template #empty>No tailor work in the selected range.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.grand-total { font-weight: 600; color: #0f172a; }
</style>
