<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'

const toast = useToast()
const auth = useAuthStore()

const canCreate = computed(() => auth.can('e-way-bills.create'))
const canEdit = computed(() => auth.can('e-way-bills.edit'))

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const perPage = ref(15)
const page = ref(1)
const statusFilter = ref<string | null>(null)
const search = ref('')

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const statusOptions = [
  { label: 'Not generated', value: 'none' },
  { label: 'Generated', value: 'generated' },
  { label: 'Cancelled', value: 'cancelled' },
  { label: 'Failed', value: 'failed' },
]
const statusColors: Record<string, string> = { generated: 'success', cancelled: 'secondary', failed: 'danger' }
const transportModes = [
  { label: 'Road', value: 'road' }, { label: 'Rail', value: 'rail' },
  { label: 'Air', value: 'air' }, { label: 'Ship', value: 'ship' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/e-way-bills', {
      params: { page: page.value, per_page: perPage.value, status: statusFilter.value || undefined, search: search.value || undefined },
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

// --- Generate dialog ---
const genVisible = ref(false)
const genRow = ref<any>(null)
const saving = ref(false)
const form = reactive({
  transport_mode: 'road', transporter_id: '', transporter_name: '',
  transport_doc_no: '', vehicle_no: '', vehicle_type: 'regular', distance_km: 0,
  from_place: '', from_pincode: '', to_place: '', to_pincode: '',
})

function openGenerate(row: any) {
  genRow.value = row
  Object.assign(form, {
    transport_mode: 'road', transporter_id: '', transporter_name: '',
    transport_doc_no: '', vehicle_no: '', vehicle_type: 'regular', distance_km: 0,
    from_place: '', from_pincode: '', to_place: '', to_pincode: '',
  })
  genVisible.value = true
}

async function submitGenerate() {
  if (form.transport_mode === 'road' && !form.vehicle_no) {
    toast.add({ severity: 'warn', summary: 'Vehicle number is required for road transport', life: 3500 })
    return
  }
  saving.value = true
  try {
    await http.post(`/e-way-bills/${genRow.value.invoice_id}/generate`, form)
    toast.add({ severity: 'success', summary: 'E-way bill generated', life: 2500 })
    genVisible.value = false
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot generate', detail: e.response?.data?.message || 'Failed', life: 6000 })
  } finally {
    saving.value = false
  }
}

async function updateVehicle(row: any) {
  const { value: vehicle } = await Swal.fire({
    title: `Update vehicle for ${row.invoice_number}`,
    input: 'text', inputValue: row.eway_bill?.vehicle_no || '',
    inputPlaceholder: 'e.g. GJ05AB1234', showCancelButton: true, confirmButtonText: 'Update',
  })
  if (!vehicle) return
  try {
    await http.post(`/e-way-bills/${row.invoice_id}/update-vehicle`, {
      vehicle_no: vehicle, transport_mode: row.eway_bill?.transport_mode || 'road',
    })
    toast.add({ severity: 'success', summary: 'Vehicle updated', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function cancel(row: any) {
  const { value: payload } = await Swal.fire({
    title: `Cancel e-way bill ${row.eway_bill?.ewb_no}?`,
    html:
      '<select id="rc" class="swal2-select" style="display:block">' +
      '<option value="1">Duplicate</option><option value="2">Order cancelled</option>' +
      '<option value="3">Data entry mistake</option><option value="4">Others</option></select>' +
      '<input id="rm" class="swal2-input" placeholder="Remarks">',
    focusConfirm: false, showCancelButton: true, confirmButtonText: 'Cancel EWB',
    preConfirm: () => ({
      cancel_reason_code: (document.getElementById('rc') as HTMLSelectElement).value,
      cancel_remarks: (document.getElementById('rm') as HTMLInputElement).value,
    }),
  })
  if (!payload) return
  if (!payload.cancel_remarks) {
    toast.add({ severity: 'warn', summary: 'Remarks are required', life: 3000 })
    return
  }
  try {
    await http.post(`/e-way-bills/${row.invoice_id}/cancel`, payload)
    toast.add({ severity: 'success', summary: 'E-way bill cancelled', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Cannot cancel', detail: e.response?.data?.message || 'Failed', life: 6000 })
  }
}

function money(v: number) { return formatCurrency(v) }

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>E-Way Bills</h1>
      <div class="header-actions">
        <span class="search-box">
          <i class="pi pi-search" />
          <InputText v-model="search" placeholder="Search invoice #..." @keyup.enter="load" />
        </span>
        <Button label="Search" outlined @click="load" />
        <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" showClear
          placeholder="All" @change="load" class="filter" />
        <TableExportButtons title="E-Way Bills" filenameBase="e-way-bills" endpoint="/e-way-bills"
          :params="() => ({ status: statusFilter || undefined, search: search || undefined })"
          :columns="[
            { key: 'invoice_number', label: 'Invoice #' },
            { key: 'invoice_date', label: 'Date' },
            { key: 'customer_name', label: 'Customer' },
            { key: 'total', label: 'Total' },
            { key: 'eway_bill.ewb_no', label: 'EWB No.' },
            { key: 'eway_bill.valid_upto', label: 'Valid Upto' },
            { key: 'eway_bill.status', label: 'Status' },
          ]" />
        <Button icon="pi pi-refresh" text rounded @click="load" title="Refresh" />
      </div>
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total"
      :rowsPerPageOptions="[15, 30, 50]" dataKey="invoice_id" @page="onPage" class="p-datatable-sm">
      <Column field="invoice_number" header="Invoice #" />
      <Column field="invoice_date" header="Date" />
      <Column field="customer_name" header="Customer" />
      <Column header="Total" style="text-align: right"><template #body="{ data }">{{ money(data.total) }}</template></Column>
      <Column header="EWB No.">
        <template #body="{ data }">{{ data.eway_bill?.ewb_no || '—' }}</template>
      </Column>
      <Column header="Valid Upto">
        <template #body="{ data }">{{ data.eway_bill?.valid_upto || '—' }}</template>
      </Column>
      <Column header="Status">
        <template #body="{ data }">
          <Tag v-if="data.eway_bill" :severity="statusColors[data.eway_bill.status] || 'info'" :value="data.eway_bill.status" />
          <span v-else class="muted">Not generated</span>
        </template>
      </Column>
      <Column header="Actions" style="width: 18rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" title="View" @click="openDetail(data)" />
          <Button v-if="canCreate && (!data.eway_bill || data.eway_bill.status === 'failed')" label="Generate" size="small" severity="success" outlined @click="openGenerate(data)" />
          <template v-if="data.eway_bill && data.eway_bill.status === 'generated'">
            <Button v-if="canEdit" label="Vehicle" size="small" text @click="updateVehicle(data)" />
            <Button v-if="canEdit" label="Cancel" size="small" severity="danger" text @click="cancel(data)" />
          </template>
        </template>
      </Column>
      <template #empty>No invoices found.</template>
    </DataTable>

    <!-- Generate dialog -->
    <Dialog v-model:visible="genVisible" modal header="Generate E-Way Bill" :style="{ width: '620px' }">
      <p class="sub" v-if="genRow">Invoice <strong>{{ genRow.invoice_number }}</strong> · {{ genRow.customer_name }}</p>
      <div class="form">
        <label>Transport Mode
          <Select v-model="form.transport_mode" :options="transportModes" optionLabel="label" optionValue="value" />
        </label>
        <label>Distance (km)
          <InputNumber v-model="form.distance_km" :min="0" :max="4000" />
        </label>
        <label>Vehicle No.
          <InputText v-model="form.vehicle_no" placeholder="GJ05AB1234" />
        </label>
        <label>Vehicle Type
          <Select v-model="form.vehicle_type" :options="[{label:'Regular',value:'regular'},{label:'Over Dimensional Cargo',value:'over_dimensional_cargo'}]" optionLabel="label" optionValue="value" />
        </label>
        <label>Transporter Name
          <InputText v-model="form.transporter_name" />
        </label>
        <label>Transporter ID / GSTIN
          <InputText v-model="form.transporter_id" />
        </label>
        <label>From Place
          <InputText v-model="form.from_place" />
        </label>
        <label>From Pincode
          <InputText v-model="form.from_pincode" maxlength="6" />
        </label>
        <label>To Place
          <InputText v-model="form.to_place" />
        </label>
        <label>To Pincode
          <InputText v-model="form.to_pincode" maxlength="6" />
        </label>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="genVisible = false" />
        <Button label="Generate" :loading="saving" @click="submitGenerate" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" title="E-Way Bill Details" :record="detailRecord" :fields="[
      { key: 'invoice_number', label: 'Invoice #' },
      { key: 'invoice_date', label: 'Date' },
      { key: 'customer_name', label: 'Customer' },
      { key: 'total', label: 'Total', format: (v) => money(v) },
      { key: 'eway_bill.ewb_no', label: 'EWB No.' },
      { key: 'eway_bill.status', label: 'Status' },
      { key: 'eway_bill.valid_upto', label: 'Valid Upto' },
      { key: 'eway_bill.transport_mode', label: 'Transport Mode' },
      { key: 'eway_bill.vehicle_no', label: 'Vehicle No.' },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header-actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
.filter { width: 170px; }
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box i { position: absolute; left: 0.75rem; color: #9ca3af; }
.search-box :deep(input) { padding-left: 2.25rem; }
.muted { color: #6b7280; font-size: 0.85rem; }
.sub { color: #6b7280; margin-bottom: 0.75rem; }
.form { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1rem; }
.form label { display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.8rem; color: #374151; }
.form :deep(.p-inputtext), .form :deep(.p-select), .form :deep(.p-inputnumber) { width: 100%; }
</style>
