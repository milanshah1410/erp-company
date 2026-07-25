<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { RouterLink } from 'vue-router'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()

const rows = ref<any[]>([])
const loading = ref(false)
const generating = ref(false)
const createdOrders = ref<any[]>([])

const canGenerate = computed(() => auth.can('advanced-inventory.create'))
const needsSupplierCount = computed(() => rows.value.filter((r) => !r.supplier).length)

const warehouseId = ref<number | null>(null)
const warehouses = ref<{ label: string; value: number }[]>([])

async function loadWarehouses() {
  const { data } = await http.get('/warehouses', { params: { per_page: 100, is_active: true } })
  warehouses.value = (data.data ?? data).map((w: any) => ({ label: w.name, value: w.id }))
}

async function load() {
  loading.value = true
  createdOrders.value = []
  try {
    const { data } = await http.get('/reorder/suggestions', {
      params: { warehouse_id: warehouseId.value || undefined },
    })
    rows.value = data.data
  } finally {
    loading.value = false
  }
}

async function generate() {
  generating.value = true
  try {
    const { data } = await http.post('/reorder/generate')
    createdOrders.value = data.created ?? []
    toast.add({
      severity: 'success',
      summary: 'Purchase orders generated',
      detail: `${createdOrders.value.length} draft PO(s) created. ${data.skipped_needs_supplier?.length ?? 0} item(s) skipped (no preferred supplier).`,
      life: 5000,
    })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Generation failed', life: 4000 })
  } finally {
    generating.value = false
  }
}

onMounted(() => {
  loadWarehouses()
  load()
})
</script>

<template>
  <div>
    <div class="header">
      <h1>Reorder Suggestions</h1>
      <div class="header-actions">
        <Select v-model="warehouseId" :options="warehouses" optionLabel="label" optionValue="value" showClear
          placeholder="All warehouses" class="wh-filter" @change="load" />
        <TableExportButtons title="Reorder Suggestions" :rows="rows" :columns="[
          { key: 'product.name', label: 'Product' },
          { key: 'product.sku', label: 'SKU' },
          { key: 'warehouse.name', label: 'Warehouse' },
          { key: 'on_hand', label: 'On Hand' },
          { key: 'reorder_level', label: 'Reorder Level' },
          { key: 'suggested_quantity', label: 'Suggested Qty' },
          { key: 'supplier.name', label: 'Supplier' },
        ]" />
        <Button v-if="canGenerate" label="Generate Purchase Orders" icon="pi pi-file-plus" :loading="generating"
          :disabled="!rows.length" @click="generate" />
      </div>
    </div>
    <p class="subtitle">Products at or below their reorder level, grouped by preferred supplier</p>
    <p v-if="needsSupplierCount" class="warning-note">
      <i class="pi pi-exclamation-triangle" /> {{ needsSupplierCount }} item(s) have no preferred supplier set on the product and will be skipped.
    </p>

    <DataTable :value="rows" :loading="loading" dataKey="product.id" class="p-datatable-sm">
      <Column header="Product">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name }}</template></Column>
      <Column header="On Hand"><template #body="{ data }">{{ data.on_hand }}</template></Column>
      <Column header="Reorder Level"><template #body="{ data }">{{ data.reorder_level }}</template></Column>
      <Column header="Suggested Qty"><template #body="{ data }">{{ data.suggested_quantity }}</template></Column>
      <Column header="Supplier">
        <template #body="{ data }">
          <span v-if="data.supplier">{{ data.supplier.name }}</span>
          <Tag v-else severity="warning" value="No supplier" />
        </template>
      </Column>
      <template #empty>No products are currently at or below their reorder level.</template>
    </DataTable>

    <div v-if="createdOrders.length" class="created-section">
      <h2>Purchase Orders Created</h2>
      <ul>
        <li v-for="po in createdOrders" :key="po.id">
          <RouterLink to="/purchase-orders">{{ po.po_number }}</RouterLink>
          — {{ po.supplier?.name }} / {{ po.warehouse?.name }} — {{ formatCurrency(po.total) }}
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.header-actions { display: inline-flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.wh-filter { width: 220px; }
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 0.5rem; }
.warning-note { color: #b45309; font-size: 0.85rem; margin-bottom: 1rem; }
.created-section { margin-top: 1.5rem; }
.created-section h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; }
.created-section ul { padding-left: 1.25rem; }
.created-section li { margin-bottom: 0.25rem; }
</style>
