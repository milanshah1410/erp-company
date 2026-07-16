<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
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

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const rows = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(15)

const employees = ref<{ label: string; value: number }[]>([])
const STATUSES = [
  { label: 'Pending', value: 'pending' }, { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' }, { label: 'Paid', value: 'paid' },
]
const filters = ref<{ employee_id: number | null; status: string | null }>({ employee_id: null, status: null })

const canCreate = computed(() => auth.can('overtime.create'))
const canEdit = computed(() => auth.can('overtime.edit'))
const canApprove = computed(() => auth.can('overtime.approve'))
const canDelete = computed(() => auth.can('overtime.delete'))

const dialogVisible = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = ref<any>({ employee_id: null, date: new Date(), hours: null, rate: null, remarks: '' })

const previewAmount = computed(() => formatCurrency((Number(form.value.hours) || 0) * (Number(form.value.rate) || 0)))

function money(v: any) { return formatCurrency(v) }
function statusSeverity(s: string) {
  return { pending: 'warn', approved: 'info', rejected: 'danger', paid: 'success' }[s] || 'secondary'
}
function fieldError(key: string): string | null { return errors.value[key]?.[0] ?? null }
function toDateString(d: Date | null): string | undefined { return d ? d.toISOString().slice(0, 10) : undefined }

async function loadEmployees() {
  const { data } = await http.get('/employees', { params: { per_page: 200, is_active: true } })
  employees.value = (data.data ?? data).map((e: any) => ({ label: e.name, value: e.id }))
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/overtime', {
      params: { employee_id: filters.value.employee_id || undefined, status: filters.value.status || undefined, page: page.value, per_page: perPage.value },
    })
    rows.value = data.data
    total.value = data.meta?.total ?? data.data.length
  } finally {
    loading.value = false
  }
}
function onPage(e: any) { page.value = e.page + 1; perPage.value = e.rows; load() }

function openCreate() {
  editingId.value = null
  form.value = { employee_id: filters.value.employee_id, date: new Date(), hours: null, rate: null, remarks: '' }
  errors.value = {}
  dialogVisible.value = true
}
function openEdit(row: any) {
  editingId.value = row.id
  form.value = { employee_id: row.employee_id, date: row.date ? new Date(row.date) : new Date(), hours: Number(row.hours), rate: Number(row.rate), remarks: row.remarks || '' }
  errors.value = {}
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { employee_id: form.value.employee_id, date: toDateString(form.value.date), hours: form.value.hours, rate: form.value.rate, remarks: form.value.remarks || undefined }
    if (editingId.value) {
      await http.put(`/overtime/${editingId.value}`, payload)
      toast.add({ severity: 'success', summary: 'Overtime updated', life: 2500 })
    } else {
      await http.post('/overtime', payload)
      toast.add({ severity: 'success', summary: 'Overtime added', life: 2500 })
    }
    dialogVisible.value = false
    load()
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Request failed', life: 4000 })
  } finally {
    saving.value = false
  }
}

async function approve(row: any) {
  try {
    await http.post(`/overtime/${row.id}/approve`)
    toast.add({ severity: 'success', summary: 'Approved', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}
async function confirmDelete(row: any) {
  const res = await Swal.fire({ title: 'Delete this overtime record?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/overtime/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

onMounted(async () => { await loadEmployees(); await load() })
</script>

<template>
  <div>
    <div class="header">
      <h1>Overtime</h1>
      <Button v-if="canCreate" label="New Overtime" icon="pi pi-plus" @click="openCreate" />
    </div>

    <div class="toolbar">
      <Select v-model="filters.employee_id" :options="employees" optionLabel="label" optionValue="value" filter showClear placeholder="All employees" class="w-60" @change="load" />
      <Select v-model="filters.status" :options="STATUSES" optionLabel="label" optionValue="value" showClear placeholder="All statuses" @change="load" />
      <TableExportButtons title="Overtime" endpoint="/overtime"
        :params="() => ({ ...(filters.employee_id ? { employee_id: filters.employee_id } : {}), ...(filters.status ? { status: filters.status } : {}) })"
        :columns="[
          { key: 'date', label: 'Date' },
          { key: 'employee_name', label: 'Employee' },
          { key: 'hours', label: 'Hours' },
          { key: 'rate', label: 'Rate' },
          { key: 'amount', label: 'Amount' },
          { key: 'status', label: 'Status' },
        ]" />
    </div>

    <DataTable :value="rows" :loading="loading" lazy paginator :rows="perPage" :totalRecords="total" :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage" class="p-datatable-sm">
      <Column field="date" header="Date" />
      <Column field="employee_name" header="Employee" />
      <Column field="hours" header="Hours" />
      <Column header="Rate"><template #body="{ data }">{{ money(data.rate) }}</template></Column>
      <Column header="Amount"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" /></template></Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" severity="secondary" @click="openDetail(data)" />
          <Button v-if="canApprove && data.status === 'pending'" label="Approve" icon="pi pi-check" text severity="success" size="small" @click="approve(data)" />
          <Button v-if="canEdit && data.status === 'pending'" icon="pi pi-pencil" text rounded size="small" @click="openEdit(data)" />
          <Button v-if="canDelete && ['pending', 'rejected'].includes(data.status)" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDelete(data)" />
        </template>
      </Column>
      <template #empty>No overtime recorded.</template>
    </DataTable>

    <Dialog v-model:visible="dialogVisible" modal :header="editingId ? 'Edit Overtime' : 'New Overtime'" :style="{ width: '460px' }">
      <div class="form">
        <div class="field">
          <label>Employee <span class="req">*</span></label>
          <Select v-model="form.employee_id" :options="employees" optionLabel="label" optionValue="value" filter placeholder="Select employee" :class="{ invalid: fieldError('employee_id') }" />
          <small v-if="fieldError('employee_id')" class="error-text">{{ fieldError('employee_id') }}</small>
        </div>
        <div class="row">
          <div class="field">
            <label>Date <span class="req">*</span></label>
            <DatePicker v-model="form.date" dateFormat="yy-mm-dd" showIcon iconDisplay="input" />
          </div>
          <div class="field">
            <label>Hours <span class="req">*</span></label>
            <InputNumber v-model="form.hours" :minFractionDigits="0" :maxFractionDigits="2" :min="0" :class="{ invalid: fieldError('hours') }" />
          </div>
        </div>
        <div class="field">
          <label>Rate / hour</label>
          <InputNumber v-model="form.rate" mode="currency" currency="INR" locale="en-IN" :min="0" />
        </div>
        <div class="field">
          <label>Amount</label>
          <div class="preview">{{ previewAmount }}</div>
        </div>
        <div class="field">
          <label>Remarks</label>
          <Textarea v-model="form.remarks" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" text @click="dialogVisible = false" />
        <Button :label="editingId ? 'Update' : 'Save'" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <DetailDialog v-model:visible="detailVisible" :record="detailRecord" title="Overtime Details" :fields="[
      { key: 'date', label: 'Date' },
      { key: 'employee_name', label: 'Employee' },
      { key: 'hours', label: 'Hours' },
      { key: 'rate', label: 'Rate / Hour', format:(v)=>v==null||v===''?'—':'₹'+Number(v).toLocaleString('en-IN',{minimumFractionDigits:2}) },
      { key: 'amount', label: 'Amount', format:(v)=>v==null||v===''?'—':'₹'+Number(v).toLocaleString('en-IN',{minimumFractionDigits:2}) },
      { key: 'status', label: 'Status' },
      { key: 'remarks', label: 'Remarks', full: true },
    ]" />
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.toolbar { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.w-60 { width: 240px; }
.form { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-size: 0.85rem; font-weight: 500; color: #374151; }
.field :deep(.p-inputnumber), .field :deep(.p-select), .field :deep(.p-datepicker), .field :deep(.p-inputtext) { width: 100%; }
.preview { font-weight: 600; font-size: 1.05rem; }
.req { color: #dc2626; }
.error-text { color: #dc2626; font-size: 0.78rem; }
.invalid :deep(.p-inputtext) { border-color: #dc2626 !important; }
</style>
