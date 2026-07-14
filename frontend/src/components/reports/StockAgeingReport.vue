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
const meta = ref<any>({})

const { exporting, exportAs } = useReportExport('/reports/stock-ageing', 'stock-ageing')

function money(v: any) {
  return formatCurrency(v)
}

const bucketSeverity: Record<string, string> = {
  '0-30': 'success',
  '31-60': 'info',
  '61-90': 'warning',
  '90+': 'danger',
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/stock-ageing')
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
    <ReportToolbar :exporting="exporting" :show-date-range="false" @refresh="load" @export="doExport" />

    <div v-if="meta.bucket_totals" class="buckets">
      <span class="bucket" v-for="(val, key) in meta.bucket_totals" :key="key">
        <Tag :value="key" :severity="bucketSeverity[key]" /> {{ money(val) }}
      </span>
      <span class="bucket grand"><strong>Total: {{ money(meta.grand_total) }}</strong></span>
    </div>

    <DataTable :value="rows" :loading="loading" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100]"
      dataKey="sku" class="p-datatable-sm">
      <Column header="Product"><template #body="{ data }">{{ data.product_name }}<br /><small style="color:#6b7280">{{ data.sku }}</small></template></Column>
      <Column field="warehouse" header="Warehouse" />
      <Column field="quantity" header="Quantity" />
      <Column header="Stock Value"><template #body="{ data }">{{ money(data.stock_value) }}</template></Column>
      <Column field="last_inward_at" header="Last Inward" />
      <Column field="days_in_stock" header="Days in Stock" />
      <Column header="Age"><template #body="{ data }"><Tag :value="data.age_bucket" :severity="bucketSeverity[data.age_bucket]" /></template></Column>
      <template #empty>No on-hand stock to age.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.buckets { display: flex; gap: 1.25rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.bucket { display: flex; gap: 0.4rem; align-items: center; color: #0f172a; }
.grand { margin-left: auto; }
</style>
