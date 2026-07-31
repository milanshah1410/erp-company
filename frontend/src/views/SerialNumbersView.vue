<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'
import CameraScanButton from '../components/CameraScanButton.vue'

const pt = usePt()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')
const status = ref<string | null>(null)

const statusOptions = computed(() => [
  { label: pt('In Stock'), value: 'in_stock' },
  { label: pt('In Transit'), value: 'in_transit' },
  { label: pt('Sold'), value: 'sold' },
  { label: pt('Damaged'), value: 'damaged' },
  { label: pt('Returned'), value: 'returned' },
])

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

/** Serial labels are barcoded — a camera scan searches for that serial. */
function onScan(code: string) {
  search.value = code
  doSearch()
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader :title="pt('Serial Numbers')" :subtitle="pt('Current status/location of each serial-tracked unit')" />

    <div class="toolbar">
      <span class="search"><i class="pi pi-search" /><InputText v-model="search" :placeholder="pt('Search serial...')" @keyup.enter="doSearch" /></span>
      <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value" :placeholder="pt('Any status')" showClear @change="doSearch" />
      <Button :label="pt('Search')" outlined @click="doSearch" />
      <CameraScanButton @scan="onScan" />
      <TableExportButtons :title="pt('Serial Numbers')" endpoint="/serial-numbers"
        :params="() => ({ search: search || undefined, status: status || undefined })"
        :columns="[
          { key: 'product.name', label: pt('Product') },
          { key: 'product.sku', label: pt('SKU') },
          { key: 'serial', label: pt('Serial #') },
          { key: 'warehouse.name', label: pt('Warehouse') },
          { key: 'status', label: pt('Status') },
          { key: 'updated_at', label: pt('Updated') },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column :header="pt('Product')">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column field="serial" :header="pt('Serial #')" />
      <Column :header="pt('Warehouse')"><template #body="{ data }">{{ data.warehouse?.name ?? '—' }}</template></Column>
      <Column :header="pt('Status')">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template>
      </Column>
      <Column :header="pt('Updated')"><template #body="{ data }">{{ new Date(data.updated_at).toLocaleString() }}</template></Column>
      <template #empty>{{ pt('No serial numbers found.') }}</template>
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
