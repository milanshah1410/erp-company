<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import http from '../../api/http'
import { useReportExport } from '../../composables/useReportExport'

const rows = ref<any[]>([])
const loading = ref(false)
const days = ref(90)

const { exporting, exportAs } = useReportExport('/reports/fast-slow-moving', 'fast-slow-moving')

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/fast-slow-moving', { params: { days: days.value } })
    rows.value = data.data
  } finally {
    loading.value = false
  }
}

function doExport(format: 'csv' | 'xlsx' | 'pdf') {
  return exportAs(format, { days: days.value })
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <label>Window</label>
      <InputNumber v-model="days" :min="1" :max="3650" suffix=" days" />
      <Button label="Refresh" icon="pi pi-refresh" outlined @click="load" />
      <span class="spacer" />
      <Button label="CSV" icon="pi pi-file" text :loading="exporting" @click="doExport('csv')" />
      <Button label="Excel" icon="pi pi-file-excel" text :loading="exporting" @click="doExport('xlsx')" />
      <Button label="PDF" icon="pi pi-file-pdf" text :loading="exporting" @click="doExport('pdf')" />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="sku" class="p-datatable-sm">
      <Column header="Product"><template #body="{ data }">{{ data.product_name }}<br /><small style="color:#6b7280">{{ data.sku }}</small></template></Column>
      <Column field="total_out" header="Qty Sold / Issued" />
      <Column header="Movement">
        <template #body="{ data }"><Tag :value="data.movement" :severity="data.movement === 'fast' ? 'success' : 'warning'" /></template>
      </Column>
      <template #empty>No movement data for this window.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.toolbar { display: flex; gap: 0.6rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.toolbar label { font-size: 0.9rem; color: #374151; }
.spacer { flex: 1; }
</style>
