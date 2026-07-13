<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ColumnGroup from 'primevue/columngroup'
import Row from 'primevue/row'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const rows = ref<any[]>([])
const loading = ref(false)
const grandTotal = ref(0)

const { exporting, exportAs } = useReportExport('/reports/valuation', 'inventory-valuation')

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/valuation')
    rows.value = data.data
    grandTotal.value = data.meta?.grand_total ?? 0
  } finally {
    loading.value = false
  }
}

function money(v: any) {
  return formatCurrency(v)
}

const grandTotalLabel = computed(() => money(grandTotal.value))

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format)
}

onMounted(load)
</script>

<template>
  <div>
    <ReportToolbar :exporting="exporting" :show-date-range="false" @refresh="load" @export="doExport" />

    <DataTable :value="rows" :loading="loading" dataKey="warehouse" class="p-datatable-sm">
      <Column field="warehouse" header="Warehouse" />
      <Column field="category" header="Category" />
      <Column field="quantity" header="Quantity" />
      <Column header="Stock Value"><template #body="{ data }">{{ money(data.stock_value) }}</template></Column>
      <template #empty>No stock on hand.</template>
      <ColumnGroup type="footer">
        <Row>
          <Column footer="Grand Total" :colspan="3" footerStyle="text-align:right; font-weight:600" />
          <Column :footer="grandTotalLabel" footerStyle="font-weight:600" />
        </Row>
      </ColumnGroup>
    </DataTable>
  </div>
</template>
