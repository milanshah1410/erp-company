<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import http from '../api/http'
import { formatCurrency } from '../utils/currency'

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')
const lowOnly = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/stock', {
      params: { page: page.value, per_page: perPage.value, search: search.value || undefined, low_stock: lowOnly.value || undefined },
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

onMounted(load)
</script>

<template>
  <div>
    <h1>Current Stock</h1>
    <p class="subtitle">On-hand quantity and valuation per warehouse</p>

    <div class="toolbar">
      <span class="search"><i class="pi pi-search" /><InputText v-model="search" placeholder="Search product..." @keyup.enter="page = 1; load()" /></span>
      <Button label="Search" outlined @click="page = 1; load()" />
      <label class="low"><ToggleSwitch v-model="lowOnly" @change="page = 1; load()" /> Low stock only</label>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Product">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="On Hand">
        <template #body="{ data }">
          {{ data.quantity }}
          <Tag v-if="data.is_low" severity="danger" value="LOW" style="margin-left: 6px" />
        </template>
      </Column>
      <Column header="Available"><template #body="{ data }">{{ data.available_quantity }}</template></Column>
      <Column header="Avg Cost"><template #body="{ data }">{{ money(data.average_cost) }}</template></Column>
      <Column header="Stock Value"><template #body="{ data }">{{ money(data.stock_value) }}</template></Column>
      <template #empty>No stock records.</template>
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
</style>
