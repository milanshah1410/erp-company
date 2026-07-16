<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()
const crud = useCrud('/dispatch-pods')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const canEdit = computed(() => auth.can('dispatch-pods.edit'))

const statusColors: Record<string, string> = {
  pending: 'warn',
  delivered: 'success',
  failed: 'danger',
}
const search = ref('')
const statusFilter = ref<string | null>('pending')
const statusOptions = [
  { label: 'Pending', value: 'pending' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Failed', value: 'failed' },
]

// --- Record POD dialog ---
const dialogVisible = ref(false)
const saving = ref(false)
const uploading = ref(false)
const current = ref<any>(null)
const form = ref<any>({ delivery_status: 'delivered', received_by: '', receiver_phone: '', delivered_at: today(), pod_remarks: '' })
const errors = ref<Record<string, string[]>>({})
const fileInput = ref<HTMLInputElement | null>(null)

const podStatusOptions = [
  { label: 'Delivered', value: 'delivered' },
  { label: 'Failed / Returned', value: 'failed' },
  { label: 'Pending', value: 'pending' },
]

function today() {
  return new Date().toISOString().slice(0, 10)
}

function openRecord(row: any) {
  current.value = row
  form.value = {
    delivery_status: row.delivery_status === 'pending' ? 'delivered' : row.delivery_status,
    received_by: row.received_by || '',
    receiver_phone: row.receiver_phone || '',
    delivered_at: (row.delivered_at ? String(row.delivered_at).slice(0, 10) : today()),
    pod_remarks: row.pod_remarks || '',
  }
  errors.value = {}
  dialogVisible.value = true
}

async function savePod() {
  if (!current.value) return
  saving.value = true
  errors.value = {}
  try {
    const { data } = await http.post(`/dispatch-pods/${current.value.id}`, form.value)
    current.value = data.data
    toast.add({ severity: 'success', summary: 'Proof of delivery saved', life: 2500 })
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

function triggerUpload() {
  fileInput.value?.click()
}

async function onFileSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file || !current.value) return
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('photo', file)
    const { data } = await http.post(`/dispatch-pods/${current.value.id}/photo`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    current.value = data.data
    toast.add({ severity: 'success', summary: 'Photo uploaded', life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Upload failed', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    uploading.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

function reload() {
  const params: Record<string, any> = {}
  if (search.value) params.search = search.value
  if (statusFilter.value) params.delivery_status = statusFilter.value
  crud.fetch(params)
}
function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

onMounted(reload)
</script>

<template>
  <div>
    <div class="header">
      <h1>Proof of Delivery</h1>
      <div class="header-actions">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search..." @keyup.enter="reload" />
        </span>
        <Button label="Search" outlined @click="reload" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All statuses" @change="reload" class="status-filter" />
        <TableExportButtons title="Proof of Delivery" endpoint="/dispatch-pods"
          :params="() => ({ ...(search ? { search } : {}), ...(statusFilter ? { delivery_status: statusFilter } : {}) })"
          :columns="[
            { key: 'dispatch_number', label: 'Dispatch #' },
            { key: 'dispatch_date', label: 'Date' },
            { key: 'customer', label: 'Customer' },
            { key: 'sales_order', label: 'Sales Order' },
            { key: 'packages', label: 'Pkgs' },
            { key: 'cod_amount', label: 'COD' },
            { key: 'delivery_status', label: 'Status' },
            { key: 'received_by', label: 'Received By' },
          ]" />
      </div>
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="dispatch_number" header="Dispatch #" />
      <Column field="dispatch_date" header="Date" />
      <Column header="Customer">
        <template #body="{ data }">{{ data.customer || '—' }}</template>
      </Column>
      <Column header="Sales Order">
        <template #body="{ data }">{{ data.sales_order || '—' }}</template>
      </Column>
      <Column field="packages" header="Pkgs" style="width: 5rem" />
      <Column header="COD">
        <template #body="{ data }">{{ Number(data.cod_amount) ? formatCurrency(data.cod_amount) : '—' }}</template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag :severity="statusColors[data.delivery_status]" :value="data.delivery_status" />
        </template>
      </Column>
      <Column header="Received By">
        <template #body="{ data }">{{ data.received_by || '—' }}</template>
      </Column>
      <Column header="Proof" style="width: 5rem">
        <template #body="{ data }">
          <a v-if="data.pod_photo_url" :href="data.pod_photo_url" target="_blank" rel="noopener" title="View proof">
            <i class="pi pi-image" style="color: #2c4a5e" />
          </a>
          <span v-else>—</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 8rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button v-if="canEdit" label="Record" size="small" outlined @click="openRecord(data)" />
        </template>
      </Column>
      <template #empty>No consignments to show. Dispatch a plan to record proof of delivery.</template>
    </DataTable>

    <!-- Record POD dialog -->
    <Dialog v-model:visible="dialogVisible" modal header="Record Proof of Delivery" :style="{ width: '520px' }">
      <div v-if="current" class="pod-summary">
        <strong>{{ current.dispatch_number }}</strong> · {{ current.customer || 'Consignment' }}
        <span v-if="current.delivery_address" class="addr">{{ current.delivery_address }}</span>
      </div>

      <div class="form-grid">
        <div class="fld">
          <label>Delivery Status *</label>
          <Select v-model="form.delivery_status" :options="podStatusOptions" optionLabel="label" optionValue="value" />
          <small v-if="errors.delivery_status" class="err">{{ errors.delivery_status[0] }}</small>
        </div>
        <div class="fld">
          <label>Delivered On</label>
          <input type="date" v-model="form.delivered_at" class="date-input" />
        </div>
        <div class="fld">
          <label>Received By</label>
          <InputText v-model="form.received_by" placeholder="Name of receiver" />
        </div>
        <div class="fld">
          <label>Receiver Phone</label>
          <InputText v-model="form.receiver_phone" placeholder="Phone" />
        </div>
        <div class="fld full">
          <label>Remarks</label>
          <Textarea v-model="form.pod_remarks" rows="2" placeholder="e.g. left with security, partial delivery" />
        </div>
      </div>

      <div class="upload-row">
        <input ref="fileInput" type="file" accept="image/*,application/pdf" style="display: none" @change="onFileSelected" />
        <Button :label="current?.pod_photo_url ? 'Replace Proof Photo' : 'Upload Proof Photo'" icon="pi pi-camera"
          outlined size="small" :loading="uploading" :disabled="!current?.id" @click="triggerUpload" />
        <a v-if="current?.pod_photo_url" :href="current.pod_photo_url" target="_blank" rel="noopener" class="view-link">
          <i class="pi pi-external-link" /> View current
        </a>
      </div>

      <template #footer>
        <Button label="Close" text @click="dialogVisible = false" />
        <Button label="Save" :loading="saving" @click="savePod" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Proof of Delivery Details" :fields="[
      { key: 'dispatch_number', label: 'Dispatch #' },
      { key: 'dispatch_date', label: 'Dispatch Date' },
      { key: 'customer', label: 'Customer' },
      { key: 'sales_order', label: 'Sales Order' },
      { key: 'packages', label: 'Packages' },
      { key: 'cod_amount', label: 'COD Amount', format: (v) => v == null || v === '' ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) },
      { key: 'delivery_status', label: 'Status' },
      { key: 'delivered_at', label: 'Delivered On' },
      { key: 'received_by', label: 'Received By' },
      { key: 'receiver_phone', label: 'Receiver Phone' },
      { key: 'delivery_address', label: 'Delivery Address', full: true },
      { key: 'pod_remarks', label: 'Remarks', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; }
.status-filter { width: 160px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.pod-summary { background: #f3f4f6; border-radius: 6px; padding: 0.6rem 0.8rem; margin-bottom: 1rem; font-size: 0.9rem; }
.pod-summary .addr { display: block; color: #6b7280; font-size: 0.82rem; margin-top: 0.2rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld.full { grid-column: 1 / -1; }
.fld label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.fld :deep(.p-select), .fld :deep(.p-inputtext), .fld :deep(textarea) { width: 100%; }
.date-input { padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.upload-row { display: flex; align-items: center; gap: 1rem; margin-top: 1.25rem; }
.view-link { font-size: 0.82rem; color: #2c4a5e; text-decoration: none; }
.err { color: #dc2626; font-size: 0.78rem; }
</style>
