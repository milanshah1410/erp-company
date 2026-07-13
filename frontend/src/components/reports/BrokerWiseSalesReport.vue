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
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)
const meta = ref<any>({})

const { exporting, exportAs } = useReportExport('/reports/broker-wise-sales', 'broker-wise-sales')

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/broker-wise-sales', { params: params() })
    rows.value = data.data
    meta.value = data.meta ?? {}
  } finally {
    loading.value = false
  }
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
    <ReportToolbar
      v-model:date-from="dateFrom"
      v-model:date-to="dateTo"
      :exporting="exporting"
      @refresh="load"
      @export="doExport"
    >
      <span class="totals">
        Sales: {{ money(meta.total_sales) }} · Commission: {{ money(meta.total_commission) }}
      </span>
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" dataKey="broker_code" class="p-datatable-sm">
      <Column field="broker_code" header="Code" />
      <Column field="broker" header="Broker" />
      <Column field="order_count" header="Orders" />
      <Column header="Sales Value"><template #body="{ data }">{{ money(data.sales_value) }}</template></Column>
      <Column header="Sales (incl. tax)"><template #body="{ data }">{{ money(data.sales_total) }}</template></Column>
      <Column header="Commission"><template #body="{ data }">{{ money(data.commission_total) }}</template></Column>
      <template #empty>No broker sales in the selected range.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.totals { font-weight: 600; color: #0f172a; }
</style>
