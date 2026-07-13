<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ColumnGroup from 'primevue/columngroup'
import Row from 'primevue/row'
import SelectButton from 'primevue/selectbutton'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const directionOptions = [
  { label: 'Input Tax (Purchases)', value: 'input' },
  { label: 'Output Tax (Sales)', value: 'output' },
]

const direction = ref<'input' | 'output'>('output')
const rows = ref<any[]>([])
const loading = ref(false)
const totalTax = ref(0)
const dateFrom = ref<Date | null>(null)
const dateTo = ref<Date | null>(null)

const endpoint = computed(() => `/reports/gst-${direction.value}`)
const { exporting, exportAs } = useReportExport(() => endpoint.value, () => `gst-${direction.value}`)

function toDateParam(d: Date | null) {
  return d ? d.toISOString().slice(0, 10) : undefined
}

function params() {
  return { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value) }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get(endpoint.value, { params: params() })
    rows.value = data.data
    totalTax.value = data.meta?.total_tax ?? 0
  } finally {
    loading.value = false
  }
}

function money(v: any) {
  return formatCurrency(v)
}

const totalTaxLabel = computed(() => money(totalTax.value))

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, params())
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar v-model:date-from="dateFrom" v-model:date-to="dateTo" :exporting="exporting" @refresh="load" @export="doExport">
      <SelectButton v-model="direction" :options="directionOptions" optionLabel="label" optionValue="value" @change="load" />
    </ReportToolbar>

    <DataTable :value="rows" :loading="loading" dataKey="hsn_code" class="p-datatable-sm">
      <Column field="hsn_code" header="HSN Code" />
      <Column header="Tax %"><template #body="{ data }">{{ data.tax_percent }}%</template></Column>
      <Column header="Taxable Value"><template #body="{ data }">{{ money(data.taxable_value) }}</template></Column>
      <Column header="Tax Amount"><template #body="{ data }">{{ money(data.tax_amount) }}</template></Column>
      <template #empty>No transactions in the selected range.</template>
      <ColumnGroup type="footer">
        <Row>
          <Column footer="Total Tax" :colspan="3" footerStyle="text-align:right; font-weight:600" />
          <Column :footer="totalTaxLabel" footerStyle="font-weight:600" />
        </Row>
      </ColumnGroup>
    </DataTable>
  </div>
</template>
