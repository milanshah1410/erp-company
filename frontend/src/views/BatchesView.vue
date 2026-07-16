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

const warehouses = ref<{ label: string; value: number }[]>([])
const warehouseFilter = ref<number | null>(null)
const expiryFilter = ref<string>('')
const expiryOptions = [
  { label: 'Any expiry', value: '' },
  { label: 'Expiring ≤ 30 days', value: '30' },
  { label: 'Expiring ≤ 90 days', value: '90' },
  { label: 'Already expired', value: 'expired' },
]

/** Translate the expiry window filter into the API's `expiring_before` date. */
function expiringBefore(): string | undefined {
  if (!expiryFilter.value) return undefined
  const d = new Date()
  if (expiryFilter.value !== 'expired') d.setDate(d.getDate() + Number(expiryFilter.value))
  return d.toISOString().slice(0, 10)
}

function listParams() {
  return {
    warehouse_id: warehouseFilter.value || undefined,
    expiring_before: expiringBefore(),
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/batches', {
      params: { page: page.value, per_page: perPage.value, ...listParams() },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  load()
}

async function loadWarehouses() {
  const { data } = await http.get('/warehouses', { params: { per_page: 100 } })
  warehouses.value = (data.data ?? data).map((w: any) => ({ label: w.name, value: w.id }))
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

function expirySeverity(row: any): string | undefined {
  if (row.is_expired) return 'danger'
  if (row.days_to_expiry !== null && row.days_to_expiry <= 30) return 'warning'
  return undefined
}

onMounted(async () => {
  await loadWarehouses()
  load()
})
</script>

<template>
  <div>
    <h1>Batches / Lots</h1>
    <p class="subtitle">On-hand quantity per batch, with expiry tracking</p>

    <div class="toolbar">
      <span class="search"><i class="pi pi-search" /><InputText v-model="search" placeholder="Filter (client-side)..." /></span>
      <Select v-model="warehouseFilter" :options="warehouses" optionLabel="label" optionValue="value" filter showClear
        placeholder="All warehouses" class="flt" @change="applyFilters" />
      <Select v-model="expiryFilter" :options="expiryOptions" optionLabel="label" optionValue="value"
        class="flt" @change="applyFilters" />
      <Button label="Refresh" outlined icon="pi pi-refresh" @click="load" />
      <TableExportButtons title="Batches" endpoint="/batches" :params="() => listParams()"
        :columns="[
          { key: 'product.name', label: 'Product' },
          { key: 'batch_number', label: 'Batch #' },
          { key: 'warehouse.name', label: 'Warehouse' },
          { key: 'quantity', label: 'Quantity' },
          { key: 'expiry_date', label: 'Expiry' },
        ]" />
    </div>

    <DataTable :value="rows.filter(r => !search || r.product?.name?.toLowerCase().includes(search.toLowerCase()) || r.batch_number?.toLowerCase().includes(search.toLowerCase()))"
      :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Product">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column field="batch_number" header="Batch #" />
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="Quantity"><template #body="{ data }">{{ data.quantity }}</template></Column>
      <Column header="Expiry">
        <template #body="{ data }">
          <span v-if="data.expiry_date">{{ data.expiry_date }}</span>
          <span v-else class="muted">No expiry</span>
          <Tag v-if="expirySeverity(data)" :severity="expirySeverity(data)" :value="data.is_expired ? 'EXPIRED' : `${data.days_to_expiry}d left`" style="margin-left: 6px" />
        </template>
      </Column>
      <template #empty>No batches found.</template>
    </DataTable>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.flt { width: 180px; }
.search { position: relative; display: flex; align-items: center; }
.search i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search :deep(input) { padding-left: 2.25rem; width: 260px; }
.muted { color: #9ca3af; }
</style>
