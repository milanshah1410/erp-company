<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import SelectButton from 'primevue/selectbutton'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const groupOptions = [
  { label: 'By Product', value: 'product' },
  { label: 'By Category', value: 'category' },
]

const groupBy = ref<'product' | 'category'>('product')
const rows = ref<any[]>([])
const loading = ref(false)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const { exporting, exportAs } = useReportExport('/reports/profitability', () => `profitability-${groupBy.value}`)

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value), group_by: groupBy.value }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/profitability', { params: params() })
    rows.value = data.data
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

const dataKey = computed(() => (groupBy.value === 'category' ? 'category' : 'sku'))

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="load" @export="doExport">
      <SelectButton v-model="groupBy" :options="groupOptions" optionLabel="label" optionValue="value" @change="load" />
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" :dataKey="dataKey" class="p-datatable-sm">
      <Column v-if="groupBy === 'product'" header="Product">
        <template #body="{ data }">{{ data.product_name }}<br /><small style="color:#6b7280">{{ data.sku }}</small></template>
      </Column>
      <Column field="category" header="Category" />
      <Column field="qty_sold" header="Qty Sold" />
      <Column header="Revenue"><template #body="{ data }">{{ money(data.revenue) }}</template></Column>
      <Column header="Cost"><template #body="{ data }">{{ money(data.cost) }}</template></Column>
      <Column header="Profit"><template #body="{ data }"><span :class="data.profit >= 0 ? 'profit' : 'loss'">{{ money(data.profit) }}</span></template></Column>
      <Column header="Margin">
        <template #body="{ data }">{{ data.margin_percent !== null ? data.margin_percent + '%' : '—' }}</template>
      </Column>
      <template #empty>No sales in the selected range.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.profit { color: #059669; font-weight: 600; }
.loss { color: #dc2626; font-weight: 600; }
</style>
