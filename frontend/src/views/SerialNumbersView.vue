<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import http from '../api/http'
import TableExportButtons from '../components/TableExportButtons.vue'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')
const status = ref<string | null>(null)

const statusOptions = [
  { label: 'In Stock', value: 'in_stock' },
  { label: 'In Transit', value: 'in_transit' },
  { label: 'Sold', value: 'sold' },
  { label: 'Damaged', value: 'damaged' },
  { label: 'Returned', value: 'returned' },
]

const statusColors: Record<string, string> = {
  in_stock: 'success',
  in_transit: 'info',
  sold: 'secondary',
  damaged: 'danger',
  returned: 'warning',
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/serial-numbers', {
      params: { page: page.value, per_page: perPage.value, search: search.value || undefined, status: status.value || undefined },
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

function doSearch() {
  page.value = 1
  load()
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Serial Numbers</h1>
    <p class="subtitle">Current status/location of each serial-tracked unit</p>

    <div class="toolbar">
      <span class="search"><i class="pi pi-search" /><InputText v-model="search" placeholder="Search serial..." @keyup.enter="doSearch" /></span>
      <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Any status" showClear @change="doSearch" />
      <Button label="Search" outlined @click="doSearch" />
      <TableExportButtons title="Serial Numbers" endpoint="/serial-numbers"
        :params="() => ({ search: search || undefined, status: status || undefined })"
        :columns="[
          { key: 'product.name', label: 'Product' },
          { key: 'product.sku', label: 'SKU' },
          { key: 'serial', label: 'Serial #' },
          { key: 'warehouse.name', label: 'Warehouse' },
          { key: 'status', label: 'Status' },
          { key: 'updated_at', label: 'Updated' },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Product">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column field="serial" header="Serial #" />
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name ?? '—' }}</template></Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <Column header="Updated"><template #body="{ data }">{{ new Date(data.updated_at).toLocaleString() }}</template></Column>
      <template #empty>No serial numbers found.</template>
    </DataTable>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 260px; }
</style>
