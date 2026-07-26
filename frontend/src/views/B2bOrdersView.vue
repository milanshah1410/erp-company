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
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const pt = usePt()
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
const statusLabels = computed<Record<string, string>>(() => ({
  pending_approval: pt('Pending approval'),
  draft: pt('Draft'),
  confirmed: pt('Confirmed'),
  backordered: pt('Backordered'),
  delivered: pt('Delivered'),
  cancelled: pt('Cancelled'),
}))

const search = ref('')
const statusFilter = ref<string | null>('pending_approval')
const statusOptions = computed(() => [
  { label: pt('Pending approval'), value: 'pending_approval' },
  { label: pt('Confirmed'), value: 'confirmed' },
  { label: pt('Backordered'), value: 'backordered' },
  { label: pt('Delivered'), value: 'delivered' },
  { label: pt('Cancelled'), value: 'cancelled' },
])

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
    toast.add({ severity: 'error', summary: pt('Could not load order'), life: 3000 })
  } finally {
    loadingDetail.value = false
  }
}

async function approve(row: any) {
  const res = await Swal.fire({
    title: pt('Approve {number}?', { number: row.so_number }),
    text: pt('The credit limit will be checked and available stock reserved. The order then enters normal fulfilment.'),
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: pt('Approve'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/b2b-orders/${row.id}/approve`)
    toast.add({ severity: 'success', summary: pt('Order approved'), life: 2500 })
    detailVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Cannot approve'), detail: e.response?.data?.message || pt('Failed'), life: 6000 })
  }
}

async function reject(row: any) {
  const res = await Swal.fire({
    title: pt('Reject {number}?', { number: row.so_number }),
    input: 'text',
    inputLabel: pt('Reason (optional)'),
    inputPlaceholder: pt('e.g. item unavailable'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Reject'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/b2b-orders/${row.id}/reject`, { reason: res.value || null })
    toast.add({ severity: 'success', summary: pt('Order rejected'), life: 2500 })
    detailVisible.value = false
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Failed'), life: 4000 })
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
    <PageHeader :title="pt('B2B Orders')" :count="crud.total.value">
      <template #actions>
        <div class="header-actions">
        <span class="search-box">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search...')" @keyup.enter="reload" />
        </span>
        <Button :label="pt('Search')" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
        :placeholder="pt('All statuses')" @change="reload" class="status-filter" />
        <TableExportButtons :title="pt('B2B Orders')" endpoint="/b2b-orders"
        :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { status: statusFilter } : {}) })"
        :columns="[
        { key: 'so_number', label: pt('Order #') },
        { key: 'customer.name', label: pt('Customer') },
        { key: 'order_date', label: pt('Date') },
        { key: 'items.length', label: pt('Items') },
        { key: 'total', label: pt('Total') },
        { key: 'status', label: pt('Status') },
        ]" />
        <Button icon="pi pi-refresh" text rounded @click="reload" :title="pt('Refresh')" />
        </div>
      </template>
    </PageHeader>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="so_number" :header="pt('Order #')" />
      <Column :header="pt('Customer')">
        <template #body="{ data }">
          <div class="cust">
            <span>{{ data.customer?.name }}</span>
            <small v-if="data.customer?.company_name" class="muted">{{ data.customer.company_name }}</small>
          </div>
        </template>
      </Column>
      <Column field="order_date" :header="pt('Date')" />
      <Column :header="pt('Items')"><template #body="{ data }">{{ data.items?.length ?? '—' }}</template></Column>
      <Column :header="pt('Total')"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column :header="pt('Status')">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.status]" :value="statusLabels[data.status] || data.status" />
        </template>
      </Column>
      <Column :header="pt('Actions')" style="width: 16rem">
        <template #body="{ data }">
          <Button :label="pt('View')" size="small" text @click="openDetail(data)" />
          <template v-if="canApprove && data.status === 'pending_approval'">
            <Button :label="pt('Approve')" size="small" severity="success" outlined @click="approve(data)" />
            <Button :label="pt('Reject')" size="small" severity="danger" text @click="reject(data)" />
          </template>
        </template>
      </Column>
      <template #empty>{{ pt('No B2B orders.') }}</template>
    </DataTable>

    <!-- Detail dialog -->
    <Dialog v-model:visible="detailVisible" modal :header="pt('B2B Order')" :style="{ width: '760px' }">
      <div v-if="loadingDetail" class="muted">{{ pt('Loading…') }}</div>
      <div v-else-if="detail">
        <div class="detail-top">
          <div class="d-cell"><span class="d-label">{{ pt('Order #') }}</span><span class="d-value">{{ detail.so_number }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Customer') }}</span><span class="d-value">{{ detail.customer?.name }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Date') }}</span><span class="d-value">{{ detail.order_date }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Status') }}</span><span class="d-value">
            <Tag :severity="statusColors[detail.status]" :value="statusLabels[detail.status] || detail.status" /></span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Warehouse') }}</span><span class="d-value">{{ detail.warehouse?.name || '—' }}</span></div>
          <div class="d-cell"><span class="d-label">{{ pt('Total') }}</span><span class="d-value">{{ money(detail.total) }}</span></div>
        </div>

        <p v-if="detail.notes" class="notes"><strong>{{ pt('Notes:') }}</strong> {{ detail.notes }}</p>
        <p v-if="detail.rejected_reason" class="notes err"><strong>{{ pt('Rejected:') }}</strong> {{ detail.rejected_reason }}</p>

        <table class="lines">
          <thead>
            <tr><th style="width: 40%">{{ pt('Product') }}</th><th>{{ pt('Qty') }}</th><th>{{ pt('Unit Price') }}</th><th>{{ pt('Tax %') }}</th><th>{{ pt('Line Total') }}</th></tr>
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
        <Button :label="pt('Close')" text @click="detailVisible = false" />
        <template v-if="canApprove && detail && detail.status === 'pending_approval'">
          <Button :label="pt('Reject')" severity="danger" text @click="reject(detail)" />
          <Button :label="pt('Approve')" severity="success" @click="approve(detail)" />
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
