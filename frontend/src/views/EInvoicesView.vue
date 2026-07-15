<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('e-invoices.create'))
const canCancel = computed(() => auth.can('e-invoices.edit'))

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const perPage = ref(15)
const page = ref(1)
const statusFilter = ref<string | null>(null)

const statusOptions = [
  { label: 'Not generated', value: 'none' },
  { label: 'Generated', value: 'generated' },
  { label: 'Cancelled', value: 'cancelled' },
  { label: 'Failed', value: 'failed' },
]
const statusColors: Record<string, string> = { generated: 'success', cancelled: 'secondary', failed: 'danger' }

const cancelReasons = [
  { label: 'Duplicate', value: '1' },
  { label: 'Data entry mistake', value: '2' },
  { label: 'Order cancelled', value: '3' },
  { label: 'Others', value: '4' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/e-invoices', {
      params: { page: page.value, per_page: perPage.value, status: statusFilter.value || undefined },
    })
    rows.value = data.data
    total.value = data.total
  } finally {
    loading.value = false
  }
}

function onPage(e: any) {
  page.value = e.page + 1
  perPage.value = e.rows
  load()
}

async function generate(row: any) {
  const res = await Swal.fire({
    title: `Generate e-invoice for ${row.invoice_number}?`,
    text: 'An IRN and signed QR code will be generated for this invoice.',
    icon: 'question', showCancelButton: true, confirmButtonText: 'Generate',
  })
  if (!res.isConfirmed) return
  try {
    await http.post(`/e-invoices/${row.invoice_id}/generate`)
    toast.add({ severity: 'success', summary: 'E-invoice generated (IRN)', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot generate', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

async function cancel(row: any) {
  const { value: form } = await Swal.fire({
    title: `Cancel IRN for ${row.invoice_number}?`,
    html:
      '<select id="rc" class="swal2-select" style="display:block">' +
      cancelReasons.map((r) => `<option value="${r.value}">${r.label}</option>`).join('') +
      '</select>' +
      '<input id="rm" class="swal2-input" placeholder="Remarks">',
    focusConfirm: false, showCancelButton: true, confirmButtonText: 'Cancel IRN',
    preConfirm: () => ({
      cancel_reason_code: (document.getElementById('rc') as HTMLSelectElement).value,
      cancel_remarks: (document.getElementById('rm') as HTMLInputElement).value,
    }),
  })
  if (!form) return
  if (!form.cancel_remarks) {
    toast.add({ severity: 'warn', summary: 'Remarks are required', life: 3000 })
    return
  }
  try {
    await http.post(`/e-invoices/${row.invoice_id}/cancel`, form)
    toast.add({ severity: 'success', summary: 'IRN cancelled', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot cancel', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

// --- Detail dialog ---
const detailVisible = ref(false)
const detail = ref<any>(null)
const qrData = computed(() => {
  const qr = detail.value?.einvoice?.signed_qr_code
  if (!qr) return null
  try {
    const payload = JSON.parse(atob(qr.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')))
    return JSON.parse(payload.data)
  } catch { return null }
})

function openDetail(row: any) {
  detail.value = row
  detailVisible.value = true
}
function money(v: number) { return formatCurrency(v) }

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>E-Invoices (IRN)</h1>
      <div class="header-actions">
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All" @change="load" class="filter" />
        <Button icon="pi pi-refresh" text rounded @click="load" title="Refresh" />
      </div>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="invoice_id" @page="onPage" class="p-datatable-sm">
      <Column field="invoice_number" header="Invoice #" />
      <Column field="invoice_date" header="Date" />
      <Column field="customer_name" header="Customer" />
      <Column header="GSTIN"><template #body="{ data }">{{ data.customer_gstin || '—' }}</template></Column>
      <Column header="Total" style="text-align: right"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="E-Invoice">
        <template #body="{ data }">
          <Tag v-if="data.einvoice" :severity="statusColors[data.einvoice.status] || 'info'" :value="data.einvoice.status" />
          <span v-else class="muted">Not generated</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 16rem">
        <template #body="{ data }">
          <Button v-if="data.einvoice && data.einvoice.status === 'generated'" label="View" size="small" text @click="openDetail(data)" />
          <Button v-if="canCreate && (!data.einvoice || data.einvoice.status === 'failed')" label="Generate" size="small" severity="success" outlined @click="generate(data)" />
          <Button v-if="canCancel && data.einvoice && data.einvoice.status === 'generated'" label="Cancel IRN" size="small" severity="danger" text @click="cancel(data)" />
        </template>
      </Column>
      <template #empty>No invoices found.</template>
    </DataTable>

    <Dialog v-model:visible="detailVisible" modal header="E-Invoice Details" :style="{ width: '640px' }">
      <div v-if="detail?.einvoice" class="detail">
        <div class="d-cell"><span class="d-label">Invoice</span><span class="d-value">{{ detail.invoice_number }}</span></div>
        <div class="d-cell"><span class="d-label">Status</span><span class="d-value">
          <Tag :severity="statusColors[detail.einvoice.status]" :value="detail.einvoice.status" /></span></div>
        <div class="d-cell full"><span class="d-label">IRN</span><span class="d-value mono">{{ detail.einvoice.irn }}</span></div>
        <div class="d-cell"><span class="d-label">Ack No.</span><span class="d-value">{{ detail.einvoice.ack_no }}</span></div>
        <div class="d-cell"><span class="d-label">Ack Date</span><span class="d-value">{{ detail.einvoice.ack_date }}</span></div>
        <div class="d-cell"><span class="d-label">Mode</span><span class="d-value">{{ detail.einvoice.mode }}</span></div>

        <div v-if="qrData" class="qr full">
          <span class="d-label">Signed QR content</span>
          <table class="qr-tbl">
            <tr v-for="(v, k) in qrData" :key="k"><th>{{ k }}</th><td class="mono">{{ v }}</td></tr>
          </table>
        </div>
      </div>
      <template #footer><Button label="Close" text @click="detailVisible = false" /></template>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.filter { width: 170px; }
.muted { color: #6b7280; font-size: 0.85rem; }
.detail { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.d-cell { display: flex; flex-direction: column; gap: 0.25rem; }
.d-cell.full, .qr.full { grid-column: 1 / -1; }
.d-label { font-size: 0.75rem; color: #6b7280; }
.d-value { font-weight: 600; font-size: 0.95rem; }
.mono { font-family: ui-monospace, monospace; font-size: 0.82rem; word-break: break-all; }
.qr-tbl { width: 100%; border-collapse: collapse; margin-top: 0.35rem; }
.qr-tbl th { text-align: left; font-size: 0.75rem; color: #6b7280; padding: 0.25rem 0.5rem; width: 40%; }
.qr-tbl td { padding: 0.25rem 0.5rem; font-size: 0.82rem; border-bottom: 1px solid #f1f5f9; }
</style>
