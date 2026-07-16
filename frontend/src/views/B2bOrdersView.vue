<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/b2b-orders')

const canApprove = computed(() => auth.can('b2b-orders.approve'))

const statusColors: Record<string, string> = {
  pending_approval: 'warn',
  draft: 'secondary',
  confirmed: 'info',
  backordered: 'warn',
  delivered: 'success',
  cancelled: 'danger',
}
const statusLabels: Record<string, string> = {
  pending_approval: 'Pending approval',
  draft: 'Draft',
  confirmed: 'Confirmed',
  backordered: 'Backordered',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
}

const search = ref('')
const statusFilter = ref<string | null>('pending_approval')
const statusOptions = [
  { label: 'Pending approval', value: 'pending_approval' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'Backordered', value: 'backordered' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Cancelled', value: 'cancelled' },
]

// --- Detail dialog ---
const detailVisible = ref(false)
const detail = ref<any>(null)
const loadingDetail = ref(false)

async function openDetail(row: any) {
  detailVisible.value = true
  loadingDetail.value = true
  detail.value = null
  try {
    const { data } = await http.get(`/b2b-orders/${row.id}`)
    detail.value = data.data
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load order', life: 3000 })
  } finally {
    loadingDetail.value = false
  }
}

async function approve(row: any) {
  const res = await Swal.fire({
    title: `Approve ${row.so_number}?`,
    text: 'The credit limit will be checked and available stock reserved. The order then enters normal fulfilment.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Approve',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/b2b-orders/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Order approved', life: 2500 })
    detailVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot approve', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

async function reject(row: any) {
  const res = await Swal.fire({
    title: `Reject ${row.so_number}?`,
    input: 'text',
    inputLabel: 'Reason (optional)',
    inputPlaceholder: 'e.g. item unavailable',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Reject',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/b2b-orders/${row.id}/reject`, { reason: res.value || null })
    toast.add({ severity: 'success', summary: 'Order rejected', life: 2500 })
    detailVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.status = statusFilter.value
  crud.fetch(params)
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}
function money(v: number) {
  return formatCurrency(v)
}

onMounted(reload)
</script>

<template>
  <div>
    <div class="header">
      <h1>B2B Orders</h1>
      <div class="header-actions">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons title="B2B Orders" endpoint="/b2b-orders"
          :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
          :columns="[
            { key: 'so_number', label: 'Order #' },
            { key: 'customer.name', label: 'Customer' },
            { key: 'order_date', label: 'Date' },
            { key: 'items.length', label: 'Items' },
            { key: 'total', label: 'Total' },
            { key: 'status', label: 'Status' },
          ]" />
        <Button icon="pi pi-refresh" text rounded @click="reload" title="Refresh" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="so_number" header="Order #" />
      <Column header="Customer">
        <template #body="{ data }">
          <div class="cust">
            <span>{{ data.customer?.name }}</span>
            <small v-if="data.customer?.company_name" class="muted">{{ data.customer.company_name }}</small>
          </div>
        </template>
      </Column>
      <Column field="order_date" header="Date" />
      <Column header="Items"><template #body="{ data }">{{ data.items?.length ?? '—' }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="statusLabels[data.status] || data.status" />
        </template>
      </Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <Button label="View" size="small" text @click="openDetail(data)" />
          <template v-if="canApprove && data.status === 'pending_approval'">
            <Button label="Approve" size="small" severity="success" outlined @click="approve(data)" />
            <Button label="Reject" size="small" severity="danger" text @click="reject(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No B2B orders.</template>
    </DataTable>

    <!-- Detail dialog -->
    <Dialog v-model:visible="detailVisible" modal header="B2B Order" :style="{ width: '760px' }">
      <div v-if="loadingDetail" class="muted">Loading…</div>
      <div v-else-if="detail">
        <div class="detail-top">
          <div class="d-cell"><span class="d-label">Order #</span><span class="d-value">{{ detail.so_number }}</span></div>
          <div class="d-cell"><span class="d-label">Customer</span><span class="d-value">{{ detail.customer?.name }}</span></div>
          <div class="d-cell"><span class="d-label">Date</span><span class="d-value">{{ detail.order_date }}</span></div>
          <div class="d-cell"><span class="d-label">Status</span><span class="d-value">
            <Tag :severity="statusColors[detail.status]" :value="statusLabels[detail.status] || detail.status" /></span></div>
          <div class="d-cell"><span class="d-label">Warehouse</span><span class="d-value">{{ detail.warehouse?.name || '—' }}</span></div>
          <div class="d-cell"><span class="d-label">Total</span><span class="d-value">{{ money(detail.total) }}</span></div>
        </div>

        <p v-if="detail.notes" class="notes"><strong>Notes:</strong> {{ detail.notes }}</p>
        <p v-if="detail.rejected_reason" class="notes err"><strong>Rejected:</strong> {{ detail.rejected_reason }}</p>

        <table class="lines">
          <thead>
            <tr><th style="width: 40%">Product</th><th>Qty</th><th>Unit Price</th><th>Tax %</th><th>Line Total</th></tr>
          </thead>
          <tbody>
            <tr v-for="(i, idx) in detail.items" :key="idx">
              <td>{{ i.product?.name || i.product_id }}<small v-if="i.product?.sku" class="muted"> ({{ i.product.sku }})</small></td>
              <td>{{ i.quantity }}</td>
              <td>{{ money(i.unit_price) }}</td>
              <td>{{ i.tax_percent }}%</td>
              <td>{{ money(i.line_total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <template #footer>
        <Button label="Close" text @click="detailVisible = false" />
        <template v-if="canApprove && detail && detail.status === 'pending_approval'">
          <Button label="Reject" severity="danger" text @click="reject(detail)" />
          <Button label="Approve" severity="success" @click="approve(detail)" />
        </template>
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.status-filter { width: 180px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.cust { display: flex; flex-direction: column; }
.muted { color: #6b7280; font-size: 0.8rem; }
.detail-top { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; padding: 0.75rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 0.75rem; }
.d-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.d-label { font-size: 0.75rem; color: #6b7280; }
.d-value { font-weight: 600; font-size: 0.95rem; }
.notes { font-size: 0.9rem; margin: 0.25rem 0 0.75rem; }
.notes.err { color: #dc2626; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.4rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
.err { color: #dc2626; }
</style>
