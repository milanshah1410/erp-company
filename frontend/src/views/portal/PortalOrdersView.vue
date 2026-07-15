<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import portalHttp from '../../api/portalHttp'
import { usePortalAuthStore } from '../../stores/portalAuth'

const toast = useToast()
const auth = usePortalAuthStore()

const orders = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const statusColors: Record<string, string> = {
  pending_approval: 'warn',
  confirmed: 'info',
  backordered: 'warn',
  delivered: 'success',
  cancelled: 'danger',
}
const statusLabels: Record<string, string> = {
  pending_approval: 'Awaiting approval',
  confirmed: 'Confirmed',
  backordered: 'Backordered',
  delivered: 'Delivered',
  cancelled: 'Cancelled / Rejected',
}

function money(v: number) {
  return auth.money(v)
}

async function load() {
  loading.value = true
  try {
    const { data } = await portalHttp.get('/orders', { params: { page: page.value, per_page: perPage.value } })
    orders.value = data.data ?? []
    total.value = data.meta?.total ?? orders.value.length
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load orders', life: 3000 })
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

const detailVisible = ref(false)
const detail = ref<any>(null)
const loadingDetail = ref(false)

async function openDetail(row: any) {
  detailVisible.value = true
  loadingDetail.value = true
  detail.value = null
  try {
    const { data } = await portalHttp.get(`/orders/${row.id}`)
    detail.value = data.data
  } catch {
    toast.add({ severity: 'error', summary: 'Could not load order', life: 3000 })
  } finally {
    loadingDetail.value = false
  }
}

async function cancelOrder(row: any) {
  const res = await Swal.fire({
    title: `Cancel ${row.so_number}?`,
    text: 'This order is still awaiting approval and will be withdrawn.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Cancel order',
  })
  if (!res.isConfirmed) return
  try {
    await portalHttp.post(`/orders/${row.id}/cancel`)
    toast.add({ severity: 'success', summary: 'Order cancelled', life: 2500 })
    detailVisible.value = false
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <h1>My Orders</h1>

    <DataTable :value="orders" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="so_number" header="Order #" />
      <Column field="order_date" header="Date" />
      <Column header="Items"><template #body="{ data }">{{ data.items?.length ?? '—' }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="Status">
        <template #body="{ data }"><Tag :severity="statusColors[data.status]" :value="statusLabels[data.status] || data.status" /></template>
      </Column>
      <Column header="" style="width: 12rem">
        <template #body="{ data }">
          <Button label="View" size="small" text @click="openDetail(data)" />
          <Button v-if="data.status === 'pending_approval'" label="Cancel" size="small" text severity="danger" @click="cancelOrder(data)" />
        </template>
      </Column>
      <template #empty>You haven't placed any orders yet.</template>
    </DataTable>

    <Dialog v-model:visible="detailVisible" modal header="Order details" :style="{ width: '680px' }">
      <div v-if="loadingDetail" class="muted">Loading…</div>
      <div v-else-if="detail">
        <div class="top">
          <div class="cell"><span class="l">Order #</span><span class="v">{{ detail.so_number }}</span></div>
          <div class="cell"><span class="l">Date</span><span class="v">{{ detail.order_date }}</span></div>
          <div class="cell"><span class="l">Status</span><span class="v">
            <Tag :severity="statusColors[detail.status]" :value="statusLabels[detail.status] || detail.status" /></span></div>
          <div class="cell"><span class="l">Total</span><span class="v">{{ money(detail.total) }}</span></div>
        </div>
        <p v-if="detail.notes" class="notes"><strong>Notes:</strong> {{ detail.notes }}</p>
        <p v-if="detail.rejected_reason" class="notes err"><strong>Not approved:</strong> {{ detail.rejected_reason }}</p>

        <table class="lines">
          <thead><tr><th style="width: 45%">Product</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
          <tbody>
            <tr v-for="(i, idx) in detail.items" :key="idx">
              <td>{{ i.product?.name || i.product_id }}</td>
              <td>{{ i.quantity }}</td>
              <td>{{ money(i.unit_price) }}</td>
              <td>{{ money(i.line_total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer>
        <Button label="Close" text @click="detailVisible = false" />
        <Button v-if="detail && detail.status === 'pending_approval'" label="Cancel order" severity="danger" text @click="cancelOrder(detail)" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.25rem; }
.muted { color: #6b7280; }
.top { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; padding: 0.75rem; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 0.75rem; }
.cell { display: flex; flex-direction: column; gap: 0.25rem; }
.l { font-size: 0.75rem; color: #6b7280; }
.v { font-weight: 600; font-size: 0.95rem; }
.notes { font-size: 0.9rem; margin: 0.25rem 0 0.75rem; }
.notes.err { color: #dc2626; }
.lines { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; font-size: 0.8rem; color: #6b7280; padding: 0.4rem; border-bottom: 1px solid #e5e7eb; }
.lines td { padding: 0.4rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
.err { color: #dc2626; }
</style>
