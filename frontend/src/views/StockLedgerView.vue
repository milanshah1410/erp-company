<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import http from '../api/http'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const typeFilter = ref<string | null>(null)
const search = ref('')
const warehouseId = ref<number | null>(null)
const warehouses = ref<{ label: string; value: number }[]>([])

async function loadWarehouses() {
  const { data } = await http.get('/warehouses', { params: { per_page: 100, is_active: true } })
  warehouses.value = (data.data ?? data).map((w: any) => ({ label: w.name, value: w.id }))
}

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
      params: {
        page: page.value,
        per_page: perPage.value,
        type: typeFilter.value || undefined,
        search: search.value || undefined,
        warehouse_id: warehouseId.value || undefined,
      },
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

onMounted(() => {
  loadWarehouses()
  load()
})
</script>

<template>
  <div>
    <PageHeader title="Stock Ledger" subtitle="Immutable record of every stock movement" />

    <div class="toolbar">
      <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search product / reference..." @keyup.enter="page = 1; load()" />
      </span>
      <Select v-model="typeFilter" :options="types" optionLabel="label" optionValue="value" placeholder="Type" @change="page = 1; load()" />
      <Select v-model="warehouseId" :options="warehouses" optionLabel="label" optionValue="value" showClear
        placeholder="All warehouses" class="wh-filter" @change="page = 1; load()" />
      <Button label="Search" outlined @click="page = 1; load()" />
      <TableExportButtons title="Stock Ledger" endpoint="/stock-ledger"
        :params="() => ({ type: typeFilter || undefined, search: search || undefined, warehouse_id: warehouseId || undefined })"
        :columns="[
          { key: 'created_at', label: 'Date' },
          { key: 'type', label: 'Type' },
          { key: 'product.name', label: 'Product' },
          { key: 'product.sku', label: 'SKU' },
          { key: 'warehouse.name', label: 'Warehouse' },
          { key: 'quantity_in', label: 'In' },
          { key: 'quantity_out', label: 'Out' },
          { key: 'balance', label: 'Balance' },
          { key: 'reference_number', label: 'Reference' },
          { key: 'user', label: 'By' },
        ]" />
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
.toolbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.wh-filter { width: 220px; }
.in { color: #059669; font-weight: 600; }
.out { color: #dc2626; font-weight: 600; }
</style>
