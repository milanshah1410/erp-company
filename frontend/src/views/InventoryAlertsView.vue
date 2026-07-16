<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()

const rows = ref<any[]>([])
const loading = ref(false)
const generating = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const status = ref<string | null>(null)
const type = ref<string | null>(null)

const canEdit = computed(() => auth.can('advanced-inventory.edit'))
const canDelete = computed(() => auth.can('advanced-inventory.delete'))

const statusOptions = [
  { label: 'Open', value: 'open' },
  { label: 'Acknowledged', value: 'acknowledged' },
  { label: 'Resolved', value: 'resolved' },
]
const typeOptions = [
  { label: 'Expiry', value: 'expiry' },
  { label: 'Low Stock', value: 'low_stock' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/inventory-alerts', {
      params: { page: page.value, per_page: perPage.value, status: status.value || undefined, type: type.value || undefined },
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

function doFilter() {
  page.value = 1
  load()
}

async function generate() {
  generating.value = true
  try {
    const { data } = await http.post('/inventory-alerts/generate')
    toast.add({ severity: 'success', summary: 'Scan complete', detail: data.message, life: 4000 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Scan failed', life: 4000 })
  } finally {
    generating.value = false
  }
}

async function acknowledge(row: any) {
  try {
    await http.post(`/inventory-alerts/${row.id}/acknowledge`)
    toast.add({ severity: 'success', summary: 'Alert acknowledged', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  }
}

async function resolve(row: any) {
  try {
    await http.post(`/inventory-alerts/${row.id}/resolve`)
    toast.add({ severity: 'success', summary: 'Alert resolved', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  }
}

async function remove(row: any) {
  try {
    await http.delete(`/inventory-alerts/${row.id}`)
    toast.add({ severity: 'success', summary: 'Alert deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

const severityColors: Record<string, string> = { critical: 'danger', warning: 'warning' }
const statusColors: Record<string, string> = { open: 'danger', acknowledged: 'warning', resolved: 'success' }

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Inventory Alerts</h1>
      <Button v-if="canEdit" label="Scan Now" icon="pi pi-refresh" :loading="generating" @click="generate" />
    </div>
    <p class="subtitle">Expiry and low-stock alerts, scanned daily (or on demand)</p>

    <div class="toolbar">
      <Select v-model="type" :options="typeOptions" optionLabel="label" optionValue="value" placeholder="Any type" showClear @change="doFilter" />
      <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Open + Acknowledged" showClear @change="doFilter" />
      <TableExportButtons title="Inventory Alerts" endpoint="/inventory-alerts"
        :params="() => ({ ...(status ? { status } : {}), ...(type ? { type } : {}) })"
        :columns="[
          { key: 'severity', label: 'Severity' },
          { key: 'type', label: 'Type' },
          { key: 'product.name', label: 'Product' },
          { key: 'product.sku', label: 'SKU' },
          { key: 'warehouse.name', label: 'Warehouse' },
          { key: 'message', label: 'Message' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[20, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column header="Severity"><template #body="{ data }"><Tag :severity="severityColors[data.severity]" :value="data.severity" /></template></Column>
      <Column header="Type"><template #body="{ data }">{{ data.type === 'expiry' ? 'Expiry' : 'Low Stock' }}</template></Column>
      <Column header="Product">
        <template #body="{ data }"><strong>{{ data.product?.name }}</strong><br /><small style="color: #6b7280">{{ data.product?.sku }}</small></template>
      </Column>
      <Column header="Warehouse"><template #body="{ data }">{{ data.warehouse?.name ?? '—' }}</template></Column>
      <Column field="message" header="Message" />
      <Column header="Status"><template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="data.status" /></template></Column>
      <Column header="Actions" style="width: 9rem">
        <template #body="{ data }">
          <Button v-if="canEdit && data.status === 'open'" icon="pi pi-check" text rounded size="small" title="Acknowledge" @click="acknowledge(data)" />
          <Button v-if="canEdit && data.status !== 'resolved'" icon="pi pi-check-circle" text rounded size="small" severity="success" title="Resolve" @click="resolve(data)" />
          <Button v-if="canDelete" icon="pi pi-trash" text rounded size="small" severity="danger" title="Delete" @click="remove(data)" />
        </template>
      </Column>
      <template #empty>No alerts. Run a scan to check for expiring batches and low stock.</template>
    </DataTable>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; }
h1 { font-size: 1.5rem; font-weight: 600; }
.subtitle { color: #6b7280; margin-bottom: 1rem; }
.toolbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
</style>
