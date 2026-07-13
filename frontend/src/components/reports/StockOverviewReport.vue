<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ReportToolbar from './ReportToolbar.vue'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'
import { formatCurrency } from '../../utils/currency'

const types = [
  { label: 'Current Stock', value: 'current-stock' },
  { label: 'Low Stock', value: 'low-stock' },
  { label: 'Negative Stock', value: 'negative-stock' },
  { label: 'Expiry Stock', value: 'expiry-stock' },
  { label: 'Dead / Slow-Moving', value: 'dead-stock' },
]

const type = ref('current-stock')
const rows = ref<any[]>([])
const loading = ref(false)
const isBatchShaped = computed(() => type.value === 'expiry-stock')
const isDeadStock = computed(() => type.value === 'dead-stock')

const { exporting, exportAs } = useReportExport(() => `/reports/${type.value}`, () => type.value)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get(`/reports/${type.value}`, { params: { per_page: 100 } })
    rows.value = data.data
  } finally {
    loading.value = false
  }
}

function money(v: any) {
  return formatCurrency(v)
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format)
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <Select v-model="type" :options="types" optionLabel="label" optionValue="value" @change="load" />
    </div>
    <ReportToolbar :exporting="exporting" :show-date-range="false" @refresh="load" @export="doExport" />

    <DataTable :value="rows" :loading="loading" dataKey="sku" class="p-datatable-sm">
      <template v-if="isDeadStock">
        <Column header="Product"><template #body="{ data }">{{ data.product?.name }}<br /><small style="color:#6b7280">{{ data.product?.sku }}</small></template></Column>
        <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
        <Column header="On Hand"><template #body="{ data }">{{ data.quantity }}</template></Column>
        <Column header="Stock Value"><template #body="{ data }">{{ money(data.stock_value) }}</template></Column>
        <Column header="Days Idle"><template #body="{ data }">{{ data.days_idle ?? '—' }}</template></Column>
      </template>
      <template v-else-if="isBatchShaped">
        <Column header="Product"><template #body="{ data }">{{ data.product_name }}<br /><small style="color:#6b7280">{{ data.sku }}</small></template></Column>
        <Column field="warehouse" header="Warehouse" />
        <Column field="batch_number" header="Batch" />
        <Column field="expiry_date" header="Expiry Date" />
        <Column field="quantity" header="Quantity" />
        <Column header="Days to Expiry"><template #body="{ data }">{{ data.days_to_expiry }}</template></Column>
        <Column header="Status"><template #body="{ data }"><Tag :value="data.is_expired ? 'Expired' : 'Active'" :severity="data.is_expired ? 'danger' : 'success'" /></template></Column>
      </template>
      <template v-else>
        <Column header="Product"><template #body="{ data }">{{ data.product_name }}<br /><small style="color:#6b7280">{{ data.sku }}</small></template></Column>
        <Column field="warehouse" header="Warehouse" />
        <Column field="quantity" header="Quantity" />
        <Column field="reserved_quantity" header="Reserved" />
        <Column field="available_quantity" header="Available" />
        <Column header="Avg Cost"><template #body="{ data }">{{ money(data.average_cost) }}</template></Column>
        <Column header="Stock Value"><template #body="{ data }">{{ money(data.stock_value) }}</template></Column>
      </template>
      <template #empty>No records found.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 0.75rem; }
</style>
