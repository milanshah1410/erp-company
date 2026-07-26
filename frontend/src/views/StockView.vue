<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')
const lowOnly = ref(false)
const warehouseId = ref<number | null>(null)
const warehouses = ref<{ label: string; value: number }[]>([])

async function loadWarehouses() {
  const { data } = await http.get('/warehouses', { params: { per_page: 100, is_active: true } })
  warehouses.value = (data.data ?? data).map((w: any) => ({ label: w.name, value: w.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/stock', {
      params: {
        page: page.value,
        per_page: perPage.value,
        search: search.value || undefined,
        low_stock: lowOnly.value || undefined,
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
function money(v: any) {
  return formatCurrency(v)
}

onMounted(() => {
  loadWarehouses()
  load()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Current Stock')" :subtitle="pt('On-hand quantity and valuation per warehouse')" />

    <div class="toolbar">
      <span class="search"><i class="pi pi-search" /><InputText v-model="search" :placeholder="pt('Search product...')" @keyup.enter="page = 1; load()" /></span>
      <Button :label="pt('Search')" outlined @click="page = 1; load()" />
      <Select v-model="warehouseId" :options="warehouses" optionLabel="label" optionValue="value" showClear
        :placeholder="pt('All warehouses')" class="wh-filter" @change="page = 1; load()" />
      <label class="low"><ToggleSwitch v-model="lowOnly" @change="page = 1; load()" /> {{ pt('Low stock only') }}</label>
      <TableExportButtons :title="pt('Current Stock')" endpoint="/stock"
        :params="() => ({ search: search || undefined, low_stock: lowOnly || undefined, warehouse_id: warehouseId || undefined })"
        :columns="[
          { key: 'product.name', label: pt('Product') },
          { key: 'product.sku', label: pt('SKU') },
          { key: 'warehouse.name', label: pt('Warehouse') },
          { key: 'quantity', label: pt('On Hand') },
          { key: 'available_quantity', label: pt('Available') },
          { key: 'average_cost', label: pt('Avg Cost') },
          { key: 'stock_value', label: pt('Stock Value') },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column :header="pt('Product')">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column :header="pt('Warehouse')"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column :header="pt('On Hand')">
        <template #body="{ data }">
          {{ data.quantity }}
          <Tag v-if="data.is_low" severity="danger" :value="pt('LOW')" style="margin-left: 6px" />
        </template>
      </Column>
      <Column :header="pt('Available')"><template #body="{ data }">{{ data.available_quantity }}</template></Column>
      <Column :header="pt('Avg Cost')"><template #body="{ data }">{{ money(data.average_cost) }}</template></Column>
      <Column :header="pt('Stock Value')"><template #body="{ data }">{{ money(data.stock_value) }}</template></Column>
      <template #empty>{{ pt('No stock records.') }}</template>
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
.low { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: #374151; margin-left: auto; }
.wh-filter { width: 220px; }
</style>
