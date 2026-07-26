<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()
const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/channel-orders')
const canImport = computed(() => auth.can('channel-orders.approve'))
const canEdit = computed(() => auth.can('channel-orders.edit'))

const integrations = ref<any[]>([])
const integrationFilter = ref<number | null>(null)
const statusFilter = ref<string | null>('pending')
const search = ref('')
const statusOptions = computed(() => [
  { label: pt('Pending'), value: 'pending' },
  { label: pt('Imported'), value: 'imported' },
  { label: pt('Ignored'), value: 'ignored' },
  { label: pt('Failed'), value: 'failed' },
])
const importColors: Record<string, string> = { pending: 'warn', imported: 'success', ignored: 'secondary', failed: 'danger' }

async function loadIntegrations() {
  const { data } = await http.get('/ecommerce-integrations')
  integrations.value = data.data.map((i: any) => ({ id: i.id, name: `${i.name} (${i.platform_label})` }))
}

function reload() {
  crud.fetch({
    ...(search.value ? { search: search.value } : {}),
    ...(integrationFilter.value ? { channel_integration_id: integrationFilter.value } : {}),
    ...(statusFilter.value ? { import_status: statusFilter.value } : {}),
  })
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

// Detail
const detailVisible = ref(false)
const detail = ref<any>(null)
async function openDetail(row: any) {
  detailVisible.value = true
  detail.value = null
  try {
    const { data } = await http.get(`/channel-orders/${row.id}`)
    detail.value = data.data
  } catch {
    toast.add({ severity: 'error', summary: pt('Could not load order'), life: 3000 })
  }
}

async function importOrder(row: any) {
  const res = await Swal.fire({
    title: pt('Import {number}?', { number: row.external_order_number || row.external_order_id }),
    text: pt('Creates a sales order on the e-commerce channel and reserves available stock. Map the listings first if items are unmatched.'),
    icon: 'question', showCancelButton: true, confirmButtonText: pt('Import'), cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    const { data } = await http.post(`/channel-orders/${row.id}/import`)
    toast.add({ severity: 'success', summary: pt('Imported'), detail: data.message, life: 4000 })
    detailVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Import failed'), detail: e.response?.data?.message || pt('Failed'), life: 7000 })
  }
}

async function ignoreOrder(row: any) {
  try {
    await http.post(`/channel-orders/${row.id}/ignore`)
    toast.add({ severity: 'success', summary: pt('Ignored'), life: 2000 })
    detailVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
  }
}

function money(v: number) {
  return formatCurrency(v)
}
function addr(a: any) {
  if (!a) return '—'
  return [a.line1, a.line2, a.city, a.state, a.pincode, a.country].filter(Boolean).join(', ')
}

onMounted(async () => {
  await loadIntegrations()
  reload()
})
</script>

<template>
  <div>
    <PageHeader :title="pt('Marketplace Orders')" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search...')" @keyup.enter="reload" />
        </span>
        <Button :label="pt('Search')" outlined @click="reload" />
        <Select v-model="integrationFilter" :options="integrations" optionLabel="name" optionValue="id" showClear
        :placeholder="pt('All channels')" class="filter" @change="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        :placeholder="pt('All statuses')" class="filter" @change="reload" />
        <TableExportButtons :title="pt('Marketplace Orders')" endpoint="/channel-orders" :params="() => ({
        ...(search ? { search } : {}),
        ...(integrationFilter ? { channel_integration_id: integrationFilter } : {}),
        ...(statusFilter ? { import_status: statusFilter } : {}),
        })" :columns="[
        { key: 'external_order_number', label: pt('Order #') },
        { key: 'integration.platform', label: pt('Channel') },
        { key: 'customer_name', label: pt('Buyer') },
        { key: 'placed_at', label: pt('Placed') },
        { key: 'items.length', label: pt('Items') },
        { key: 'total', label: pt('Total') },
        { key: 'import_status', label: pt('Status') },
        ]" />
        <Button icon="pi pi-refresh" text rounded @click="reload" :title="pt('Refresh')" />
        </div>
      </template>
    </PageHeader>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column :header="pt('Order #')">
        <template #body="{ data }">
          <strong>{{ data.external_order_number || data.external_order_id }}</strong>
        </template>
      </Column>
      <Column :header="pt('Channel')">
        <template #body="{ data }"><span class="cap">{{ data.integration?.platform }}</span></template>
      </Column>
      <Column field="customer_name" :header="pt('Buyer')" />
      <Column :header="pt('Placed')">
        <template #body="{ data }">{{ data.placed_at ? new Date(data.placed_at).toLocaleDateString() : '—' }}</template>
      </Column>
      <Column :header="pt('Items')"><template #body="{ data }">{{ data.items?.length ?? 0 }}</template></Column>
      <Column :header="pt('Total')"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :severity="importColors[data.import_status]" :value="data.import_status" />
          <div v-if="data.sales_order" class="linked">→ {{ data.sales_order.so_number }}</div>
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 15rem">
        <template #body="{ data }">
          <Button :label="pt('View')" size="small" text @click="openDetail(data)" />
          <template v-if="data.import_status === 'pending' || data.import_status === 'failed'">
            <Button :label="pt('Import')" size="small" severity="success" outlined :disabled="!canImport" @click="importOrder(data)" />
            <Button :label="pt('Ignore')" size="small" text severity="secondary" :disabled="!canEdit" @click="ignoreOrder(data)" />
          </template>
        </template>
      </Column>
      <template #empty>{{ pt('No marketplace orders. Run a sync from the E-commerce Integration screen.') }}</template>
    </DataTable>

    <Dialog v-model:visible="detailVisible" modal :header="pt('Marketplace Order')" :style="{ width: '720px' }">
      <div v-if="!detail" class="muted">{{ pt('Loading…') }}</div>
      <div v-else>
        <div class="detail-top">
          <div class="d-cell"><span class="d-label">{{ pt('Order #') }}</span><span class="d-value">{{ detail.external_order_number || detail.external_order_id }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Channel') }}</span><span class="d-value cap">{{ detail.integration?.platform }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Status') }}</span><span class="d-value"><Tag :severity="importColors[detail.import_status]" :value="detail.import_status" /></span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Buyer') }}</span><span class="d-value">{{ detail.customer_name || '—' }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Phone') }}</span><span class="d-value">{{ detail.customer_phone || '—' }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Payment') }}</span><span class="d-value">{{ detail.financial_status || '—' }}</span></div>
        </div>
        <p class="ship"><strong>{{ pt('Ship to:') }}</strong> {{ addr(detail.shipping_address) }}</p>
        <p v-if="detail.import_error" class="notes err"><strong>{{ pt('Import error:') }}</strong> {{ detail.import_error }}</p>

        <table class="lines">
          <thead><tr><th style="width: 45%">{{ pt('Item') }}</th><th>{{ pt('SKU') }}</th><th>{{ pt('Qty') }}</th><th>{{ pt('Price') }}</th></tr></thead>
          <tbody>
            <tr v-for="(i, idx) in detail.items" :key="idx">
              <td>{{ i.name }}</td>
              <td>{{ i.sku || '—' }}</td>
              <td>{{ i.quantity }}</td>
              <td>{{ money(i.price) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer>
        <Button :label="pt('Close')" text @click="detailVisible = false" />
        <template v-if="detail && (detail.import_status === 'pending' || detail.import_status === 'failed')">
          <Button :label="pt('Ignore')" text severity="secondary" :disabled="!canEdit" @click="ignoreOrder(detail)" />
          <Button :label="pt('Import')" severity="success" :disabled="!canImport" @click="importOrder(detail)" />
        </template>
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.filter { width: 190px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.cap { text-transform: capitalize; }
.linked { font-size: 0.75rem; color: #16a34a; }
.muted { color: #6b7280; }
.detail-top { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; padding: 0.75rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 0.75rem; }
.d-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.d-label { font-size: 0.75rem; color: #6b7280; }
.d-value { font-weight: 600; font-size: 0.95rem; }
.ship { font-size: 0.9rem; margin: 0 0 0.75rem; }
.notes { font-size: 0.9rem; }
.notes.err { color: #dc2626; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.4rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
</style>
