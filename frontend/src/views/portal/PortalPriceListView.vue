<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import portalHttp from '../../api/portalHttp'
import { usePortalAuthStore } from '../../stores/portalAuth'

const toast = useToast()
const auth = usePortalAuthStore()

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(30)
const search = ref('')

const stockSeverity: Record<string, string> = { in: 'success', low: 'warn', out: 'danger' }
const stockLabel: Record<string, string> = { in: 'In stock', low: 'Low', out: 'Out' }

function money(v: number) {
  return auth.money(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await portalHttp.get('/price-list', {
      params: { search: search.value || undefined, page: page.value, per_page: perPage.value },
    })
    rows.value = data.data ?? []
    total.value = data.meta?.total ?? rows.value.length
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load price list', life: 3000 })
  } finally {
    loading.value = false
  }
}

function doSearch() {
  page.value = 1
  load()
}
function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="head">
      <div>
        <h1>Price List</h1>
        <p class="sub">Your prices ({{ auth.customer?.price_level }} tier).</p>
      </div>
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" placeholder="Search products..." @keyup.enter="doSearch" />
      </span>
      <Button label="Search" outlined @click="doSearch" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="sku" header="SKU" />
      <Column field="name" header="Product" />
      <Column field="category" header="Category" />
      <Column header="Unit"><template #body="{ data }">{{ data.unit || '—' }}</template></Column>
      <Column header="Your Price"><template #body="{ data }"><strong>{{ money(data.price) }}</strong></template></Column>
      <Column header="Availability">
        <template #body="{ data }">
          <Tag :severity="stockSeverity[data.stock_status]" :value="stockLabel[data.stock_status]" />
          <span class="qty">{{ data.available_quantity }}</span>
        </template>
      </Column>
      <template #empty>No products found.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.head h1 { font-size: 1.5rem; font-weight: 600; }
.sub { color: #6b7280; font-size: 0.85rem; text-transform: capitalize; }
.head > div { margin-right: auto; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.7rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.2rem; width: 240px; }
.qty { margin-left: 0.5rem; color: #6b7280; font-size: 0.85rem; }
</style>
