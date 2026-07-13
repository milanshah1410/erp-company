<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import http from '../api/http'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const typeFilter = ref<string | null>(null)

const types = [
  { label: 'All types', value: null },
  { label: 'Opening', value: 'opening' },
  { label: 'Purchase', value: 'purchase' },
  { label: 'Sale', value: 'sale' },
  { label: 'Transfer In', value: 'transfer_in' },
  { label: 'Transfer Out', value: 'transfer_out' },
  { label: 'Adjustment', value: 'adjustment' },
  { label: 'Damage', value: 'damage' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/stock-ledger', {
      params: { page: page.value, per_page: perPage.value, type: typeFilter.value || undefined },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}
function fmt(d: string) {
  return d ? new Date(d).toLocaleString() : ''
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Stock Ledger</h1>
    <p class="subtitle">Immutable record of every stock movement</p>

    <div class="toolbar">
      <Select v-model="typeFilter" :options="types" optionLabel="label" optionValue="value" placeholder="Type" @change="page = 1; load()" />
      <Button label="Refresh" icon="pi pi-refresh" outlined @click="load()" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Date"><template #body="{ data }">{{ fmt(data.created_at) }}</template></Column>
      <Column header="Type"><template #body="{ data }"><Tag :value="data.type.replace('_', ' ')" /></template></Column>
      <Column header="Product"><template #body="{ data }">{{ data.product?.name }}<br /><small style="color:#6b7280">{{ data.product?.sku }}</small></template></Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="In"><template #body="{ data }"><span v-if="Number(data.quantity_in) > 0" class="in">+{{ data.quantity_in }}</span></template></Column>
      <Column header="Out"><template #body="{ data }"><span v-if="Number(data.quantity_out) > 0" class="out">-{{ data.quantity_out }}</span></template></Column>
      <Column field="balance" header="Balance" />
      <Column field="reference_number" header="Reference" />
      <Column header="By"><template #body="{ data }">{{ data.user }}</template></Column>
      <template #empty>No movements recorded yet.</template>
    </DataTable>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
.in { color: #059669; font-weight: 600; }
.out { color: #dc2626; font-weight: 600; }
</style>
