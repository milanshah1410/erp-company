<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const rows = ref<any[]>([])
const loading = ref(false)
const days = ref(90)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/reports/dead-stock', { params: { days: days.value } })
    rows.value = data.data
  } finally {
    loading.value = false
  }
}

function money(v: any) {
  return formatCurrency(v)
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Dead Stock / Slow-Moving Report</h1>
    <p class="subtitle">On-hand stock with no outward movement in the selected window</p>

    <div class="toolbar">
      <label>Idle for at least</label>
      <InputNumber v-model="days" :min="1" :max="3650" suffix=" days" />
      <Button label="Refresh" icon="pi pi-refresh" @click="load" />
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="product.id" class="p-datatable-sm">
      <Column header="Product">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="On Hand"><template #body="{ data }">{{ data.quantity }}</template></Column>
      <Column header="Stock Value"><template #body="{ data }">{{ money(data.stock_value) }}</template></Column>
      <Column header="Last Movement">
        <template #body="{ data }">{{ data.last_movement_at ? new Date(data.last_movement_at).toLocaleDateString() : 'Never' }}</template>
      </Column>
      <Column header="Days Idle"><template #body="{ data }">{{ data.days_idle ?? '—' }}</template></Column>
      <template #empty>No dead stock found for this window.</template>
    </DataTable>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
.toolbar label { font-size: 0.9rem; color: #374151; }
</style>
